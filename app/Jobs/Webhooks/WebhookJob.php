<?php

namespace App\Jobs\Webhooks;

use App\Services\Webhooks\WebhookTriggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job WebhookJob
 * 
 * Envia eventos via webhook para URLs externas (frontend).
 * Executa de forma assíncrona na fila para não bloquear o processamento principal.
 * Similar ao WebhookJob do Chatwoot.
 * 
 * @package App\Jobs\Webhooks
 */
class WebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de tentativas em caso de falha
     */
    public $tries = 3;

    /**
     * Tempo de espera entre tentativas (em segundos)
     */
    public $backoff = [5, 15, 30];

    /**
     * URL do webhook
     * 
     * @var string
     */
    protected string $url;

    /**
     * Payload do evento
     * 
     * @var array
     */
    protected array $payload;

    /**
     * Tipo do webhook (para logs)
     * 
     * @var string|null
     */
    protected ?string $webhookType;

    /**
     * Construtor
     * 
     * @param string $url URL do webhook
     * @param array $payload Payload do evento
     * @param string|null $webhookType Tipo do webhook
     */
    public function __construct(string $url, array $payload, ?string $webhookType = null)
    {
        $this->url = $url;
        $this->payload = $payload;
        $this->webhookType = $webhookType;
    }

    /**
     * Execute the job.
     * 
     * @return void
     */
    public function handle(): void
    {
        Log::info('[WEBHOOK JOB] Job iniciado', [
            'url' => $this->url,
            'event' => $this->payload['event'] ?? 'unknown',
            'attempt' => $this->attempts(),
        ]);

        $success = WebhookTriggerService::execute($this->url, $this->payload, $this->webhookType);
        
        if ($success) {
            Log::info('[WEBHOOK JOB] Webhook enviado com sucesso', [
                'url' => $this->url,
                'event' => $this->payload['event'] ?? 'unknown',
            ]);
        } else {
            Log::warning('[WEBHOOK JOB] Falha ao enviar webhook', [
                'url' => $this->url,
                'event' => $this->payload['event'] ?? 'unknown',
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);
            
            if ($this->attempts() < $this->tries) {
                // Re-enfileira para retry
                $backoff = $this->backoff[$this->attempts() - 1] ?? 5;
                Log::info('[WEBHOOK JOB] Re-enfileirando para retry', [
                    'backoff' => $backoff,
                    'next_attempt' => $this->attempts() + 1,
                ]);
                $this->release($backoff);
            }
        }
    }

    /**
     * Handle a job failure.
     * 
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[WEBHOOK] Webhook job failed after all retries', [
            'url' => $this->url,
            'type' => $this->webhookType,
            'event' => $this->payload['event'] ?? 'unknown',
            'exception' => $exception->getMessage(),
        ]);
    }
}

