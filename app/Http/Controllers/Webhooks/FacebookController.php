<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\Webhooks\FacebookEventsJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller FacebookController
 * 
 * Recebe e processa webhooks do Facebook Messenger.
 * Aplica validação de tokens e enfileira jobs para processamento assíncrono.
 * Segue o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Webhooks
 */
class FacebookController extends Controller
{
    /**
     * Verifica o webhook (chamado pelo Meta para validação)
     * 
     * @param Request $request
     * @return Response
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        Log::info('[FACEBOOK WEBHOOK] Verificação recebida', [
            'mode' => $mode,
            'has_token' => !empty($token),
            'has_challenge' => !empty($challenge),
            'ip' => $request->ip(),
        ]);

        if ($mode !== 'subscribe') {
            Log::warning('[FACEBOOK WEBHOOK] Modo inválido', [
                'mode' => $mode,
                'expected' => 'subscribe',
            ]);
            return response('', 403);
        }

        // Busca o token das credenciais da AppConfig (prioridade) ou fallback para env
        $verifyToken = \App\Support\AppConfigHelper::get('facebook', 'webhook_verify_token')
            ?? config('services.facebook.webhook_verify_token');

        if (!$verifyToken) {
            Log::error('[FACEBOOK WEBHOOK] Token de verificação não configurado', [
                'app_config_exists' => \App\Support\AppConfigHelper::isConfigured('facebook'),
                'env_exists' => !empty(config('services.facebook.webhook_verify_token')),
            ]);
            return response('', 403);
        }

        if ($token !== $verifyToken) {
            Log::warning('[FACEBOOK WEBHOOK] Token de verificação inválido', [
                'received_token' => $token ? '***' : 'empty',
                'expected_token' => $verifyToken ? '***' : 'not configured',
                'tokens_match' => $token === $verifyToken,
            ]);
            return response('', 403);
        }

        Log::info('[FACEBOOK WEBHOOK] Webhook verificado com sucesso', [
            'challenge_length' => strlen($challenge ?? ''),
        ]);

        return response($challenge, 200);
    }

    /**
     * Processa o payload do webhook
     * 
     * @param Request $request
     * @return Response
     */
    public function events(Request $request): Response
    {
        $object = $request->input('object');

        if (strtolower($object) !== 'page') {
            Log::warning("Message is not received from the facebook webhook event: {$object}");
            return response(['error' => 'Invalid object'], 422)->header('Content-Type', 'application/json');
        }

        $entry = $request->input('entry', []);

        Log::info('[FACEBOOK WEBHOOK] Eventos recebidos', [
            'object' => $object,
            'entry_count' => count($entry),
        ]);

        foreach ($entry as $entryData) {
            $messaging = $entryData['messaging'] ?? [];
            
            Log::info('[FACEBOOK WEBHOOK] Processando entry', [
                'messaging_count' => count($messaging),
                'entry_keys' => array_keys($entryData),
            ]);
            
            foreach ($messaging as $messageData) {
                $hasMessage = isset($messageData['message']);
                $hasSender = isset($messageData['sender']);
                $hasRecipient = isset($messageData['recipient']);
                $hasText = isset($messageData['message']['text']);
                $hasAttachments = !empty($messageData['message']['attachments'] ?? []);
                
                Log::info('[FACEBOOK WEBHOOK] Processando mensagem', [
                    'has_message' => $hasMessage,
                    'has_sender' => $hasSender,
                    'has_recipient' => $hasRecipient,
                    'has_text' => $hasText,
                    'has_attachments' => $hasAttachments,
                    'is_echo' => $messageData['message']['is_echo'] ?? false,
                    'sender_id' => $messageData['sender']['id'] ?? null,
                    'recipient_id' => $messageData['recipient']['id'] ?? null,
                    'message_keys' => $hasMessage ? array_keys($messageData['message']) : [],
                    'payload_preview' => json_encode($messageData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                
                // Validação: precisa ter sender e recipient
                if (!$hasSender || !$hasRecipient) {
                    Log::warning('[FACEBOOK WEBHOOK] Mensagem sem sender ou recipient, pulando', [
                        'has_sender' => $hasSender,
                        'has_recipient' => $hasRecipient,
                        'payload' => $messageData,
                    ]);
                    continue;
                }
                
                // Verifica se contém echo event (mensagem enviada por nós)
                if (!empty($messageData['message']['is_echo'])) {
                    // Adiciona delay para evitar race condition
                    FacebookEventsJob::dispatch(json_encode($messageData))
                        ->delay(now()->addSeconds(2))
                        ->onQueue('low');
                    Log::info('[FACEBOOK WEBHOOK] Job de echo enfileirado');
                } else {
                    FacebookEventsJob::dispatch(json_encode($messageData))->onQueue('low');
                    Log::info('[FACEBOOK WEBHOOK] Job de mensagem enfileirado', [
                        'sender_id' => $messageData['sender']['id'] ?? null,
                        'recipient_id' => $messageData['recipient']['id'] ?? null,
                    ]);
                }
            }
        }

        return response(['status' => 'ok'], 200)->header('Content-Type', 'application/json');
    }
}
