<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\Webhooks\InstagramEventsJob;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Controller InstagramController
 * 
 * Recebe e processa webhooks do Instagram.
 * Aplica validação de tokens e enfileira jobs para processamento assíncrono.
 * Segue o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Webhooks
 */
class InstagramController extends Controller
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

        if ($mode !== 'subscribe') {
            return response('', 403);
        }

        // Busca o token das credenciais da AppConfig (prioridade) ou fallback para env
        $verifyToken = \App\Support\AppConfigHelper::get('instagram', 'webhook_verify_token')
            ?? config('services.instagram.webhook_verify_token');

        if (!$verifyToken || $token !== $verifyToken) {
            Log::warning('Instagram webhook verification failed', [
                'received_token' => $token ? '***' : 'empty',
                'expected_token' => $verifyToken ? '***' : 'not configured',
            ]);
            return response('', 403);
        }

        Log::info('Instagram webhook verified');

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
        Log::info('[INSTAGRAM WEBHOOK] Webhook recebido no controller', [
            'channel' => 'instagram',
            'method' => $request->method(),
            'has_object' => $request->has('object'),
        ]);

        $object = $request->input('object');

        if (strtolower($object) !== 'instagram') {
            Log::warning("[INSTAGRAM WEBHOOK] Message is not received from the instagram webhook event: {$object}", [
                'channel' => 'instagram',
            ]);
            return response(['error' => 'Invalid object'], 422);
        }

        $entryParams = $request->input('entry', []);

        Log::info('[INSTAGRAM WEBHOOK] Payload extraído', [
            'channel' => 'instagram',
            'entry_count' => is_array($entryParams) ? count($entryParams) : 0,
            'has_echo' => $this->containsEchoEvent($entryParams),
            'entry_structure' => array_keys($entryParams[0] ?? []),
            'entry_data_sample' => isset($entryParams[0]) ? [
                'has_messaging' => isset($entryParams[0]['messaging']),
                'messaging_count' => isset($entryParams[0]['messaging']) ? count($entryParams[0]['messaging']) : 0,
            ] : null,
        ]);

        // Verifica se contém echo event (mensagem enviada por nós)
        if ($this->containsEchoEvent($entryParams)) {
            Log::info('[INSTAGRAM WEBHOOK] Echo event detectado, adicionando delay');
            // Adiciona delay para evitar race condition onde echo chega antes da API completar
            // Usa fila 'high' para prioridade (mensagens recebidas são críticas)
            InstagramEventsJob::dispatch($entryParams)
                ->delay(now()->addSeconds(2))
                ->onQueue('high');
            Log::info('[INSTAGRAM WEBHOOK] Job enfileirado com delay na fila high');
        } else {
            Log::info('[INSTAGRAM WEBHOOK] Evento normal, enfileirando imediatamente');
            try {
                Log::info('[INSTAGRAM WEBHOOK] Preparando para enfileirar job', [
                    'entry_params_type' => gettype($entryParams),
                    'entry_params_count' => is_array($entryParams) ? count($entryParams) : 0,
                    'queue_connection' => config('queue.default'),
                    'queue_driver' => config('queue.connections.' . config('queue.default') . '.driver'),
                ]);
                
                // Usa fila 'high' para prioridade (mensagens recebidas são críticas)
                InstagramEventsJob::dispatch($entryParams)->onQueue('high');
                
                Log::info('[INSTAGRAM WEBHOOK] Job enfileirado imediatamente na fila high', [
                    'job_class' => InstagramEventsJob::class,
                    'queue' => 'high',
                ]);
                
                // Verifica se o job foi inserido na tabela
                $jobsCount = DB::table('jobs')->where('queue', 'low')->count();
                Log::info('[INSTAGRAM WEBHOOK] Jobs na fila low após dispatch', [
                    'jobs_count' => $jobsCount,
                ]);
                
                // Verifica jobs recentes
                $recentJobs = DB::table('jobs')
                    ->where('queue', 'low')
                    ->orderBy('created_at', 'desc')
                    ->limit(3)
                    ->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at']);
                Log::info('[INSTAGRAM WEBHOOK] Jobs recentes na fila', [
                    'recent_jobs' => $recentJobs->map(function ($job) {
                        return [
                            'id' => $job->id,
                            'queue' => $job->queue,
                            'attempts' => $job->attempts,
                            'reserved_at' => $job->reserved_at,
                            'available_at' => $job->available_at,
                            'created_at' => $job->created_at,
                        ];
                    })->toArray(),
                ]);
            } catch (\Exception $e) {
                Log::error('[INSTAGRAM WEBHOOK] Erro ao enfileirar job', [
                    'error' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        }

        Log::info('[INSTAGRAM WEBHOOK] Job enfileirado com sucesso', [
            'queue' => config('queue.default'),
            'queue_connection' => config('queue.connections.' . config('queue.default') . '.driver'),
        ]);

        return response(['status' => 'ok'], 200);
    }

    /**
     * Verifica se contém echo event
     * 
     * @param array $entryParams
     * @return bool
     */
    protected function containsEchoEvent(array $entryParams): bool
    {
        if (!is_array($entryParams)) {
            return false;
        }

        foreach ($entryParams as $entry) {
            $messaging = $entry['messaging'] ?? [];
            foreach ($messaging as $message) {
                if (!empty($message['message']['is_echo'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
