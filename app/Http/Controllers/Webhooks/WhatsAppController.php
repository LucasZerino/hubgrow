<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\Webhooks\WhatsAppEventsJob;
use App\Models\Channel\WhatsAppChannel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller WhatsAppController
 * 
 * Recebe e processa webhooks do WhatsApp.
 * Aplica validação de tokens e enfileira jobs para processamento assíncrono.
 * Segue o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Webhooks
 */
class WhatsAppController extends Controller
{
    /**
     * Verifica o webhook (chamado pelo Meta para validação)
     * 
     * @param Request $request
     * @param string $phoneNumber
     * @return Response
     */
    public function verify(Request $request, string $phoneNumber): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe') {
            return response('', 403);
        }

        $channel = WhatsAppChannel::where('phone_number', $phoneNumber)->first();

        if (!$channel) {
            return response('', 404);
        }

        $verifyToken = $channel->provider_config['webhook_verify_token'] ?? null;

        if ($token !== $verifyToken) {
            return response('', 403);
        }

        Log::info('WhatsApp webhook verified', [
            'phone_number' => $phoneNumber
        ]);

        return response($challenge, 200);
    }

    /**
     * Processa o payload do webhook
     * 
     * Estratégia para evitar sobrecarga (baseado no Chatwoot):
     * 1. Validação rápida (apenas essencial)
     * 2. Enfileira job imediatamente (não processa aqui!)
     * 3. Retorna 200 OK imediatamente
     * 4. Job processa assincronamente na fila 'low'
     * 
     * @param Request $request
     * @param string $phoneNumber
     * @return Response
     */
    public function processPayload(Request $request, string $phoneNumber): Response
    {
        // Validação rápida - apenas o essencial
        $channel = WhatsAppChannel::where('phone_number', $phoneNumber)->first();

        if (!$channel) {
            Log::warning('WhatsApp webhook for unknown channel', [
                'phone_number' => $phoneNumber
            ]);
            // Retorna 200 mesmo para não expor informações
            return response(['status' => 'ok'], 200)->header('Content-Type', 'application/json');
        }

        if (!$channel->account->isActive()) {
            Log::warning('WhatsApp webhook for inactive account', [
                'phone_number' => $phoneNumber,
                'account_id' => $channel->account_id
            ]);
            return response(['status' => 'ok'], 200)->header('Content-Type', 'application/json');
        }

        // Prepara parâmetros
        $params = $request->all();
        $params['phone_number'] = $phoneNumber;

        // Enfileira job para processamento assíncrono
        // Usa fila 'low' para não bloquear operações críticas
        WhatsAppEventsJob::dispatch($params)->onQueue('low');

        // Retorna imediatamente (não espera processamento!)
        return response(['status' => 'ok'], 200)->header('Content-Type', 'application/json');
    }
}
