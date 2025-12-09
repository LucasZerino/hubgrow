<?php

namespace App\Jobs\Webhooks;

use App\Exceptions\LockAcquisitionException;
use App\Jobs\MutexJob;
use App\Models\Channel\WhatsAppChannel;
use App\Services\WhatsApp\IncomingMessageService;
use App\Support\Redis\RedisKeys;
use Illuminate\Support\Facades\Log;

/**
 * Job WhatsAppEventsJob
 * 
 * Processa eventos recebidos via webhook do WhatsApp.
 * Aplica idempotência através de locks distribuídos.
 * 
 * IMPORTANTE: Este job roda na fila 'low' para não bloquear
 * operações críticas. O webhook apenas enfileira e retorna 200 OK.
 * 
 * @package App\Jobs\Webhooks
 */
class WhatsAppEventsJob extends MutexJob
{
    /**
     * Fila padrão (baixa prioridade para não bloquear operações críticas)
     */
    public $queue = 'low';

    /**
     * Número de tentativas para adquirir lock
     */
    public $tries = 8;

    /**
     * Tempo de espera entre tentativas (em segundos)
     */
    public $backoff = 1;

    /**
     * Execute the job.
     *
     * @param array $params Parâmetros do webhook
     * @return void
     */
    public function handle(array $params): void
    {
        $channel = $this->findChannel($params);

        if (!$channel || !$channel->account->isActive()) {
            Log::warning("WhatsApp inbox not found or account inactive", [
                'phone_number' => $params['phone_number'] ?? 'unknown',
                'channel_found' => $channel !== null,
                'inbox_id' => $channel?->inbox?->id,
            ]);
            return;
        }

        if (!$channel->inbox) {
            Log::warning("WhatsApp channel found but inbox not found", [
                'phone_number' => $params['phone_number'] ?? 'unknown',
                'channel_id' => $channel->id,
            ]);
            return;
        }

        // Cria chave de lock baseada no sender e recipient
        $senderId = $this->extractSenderId($params);
        $recipientId = $this->extractRecipientId($params);
        
        if ($senderId && $recipientId) {
            $lockKey = sprintf(
                RedisKeys::WHATSAPP_MESSAGE_MUTEX,
                $senderId,
                $recipientId
            );

            // Processa com lock distribuído para evitar duplicação
            $this->withLock($lockKey, 1, function () use ($channel, $params) {
                $service = new IncomingMessageService($channel->inbox, $params);
                $service->process();
            });
        } else {
            // Se não conseguir extrair IDs, processa sem lock (menos seguro)
            $service = new IncomingMessageService($channel->inbox, $params);
            $service->process();
        }
    }

    /**
     * Retry the job if lock acquisition fails
     *
     * @param LockAcquisitionException $exception
     * @return void
     */
    public function retryUntil(): \DateTime
    {
        return now()->addSeconds(10);
    }

    /**
     * Encontra o canal WhatsApp baseado nos parâmetros
     * 
     * @param array $params
     * @return WhatsAppChannel|null
     */
    private function findChannel(array $params): ?WhatsAppChannel
    {
        if (isset($params['phone_number'])) {
            return WhatsAppChannel::where('phone_number', $params['phone_number'])->first();
        }

        // Tenta encontrar pelo payload do webhook
        if (isset($params['entry'][0]['changes'][0]['value']['metadata']['display_phone_number'])) {
            $phoneNumber = '+' . $params['entry'][0]['changes'][0]['value']['metadata']['display_phone_number'];
            return WhatsAppChannel::where('phone_number', $phoneNumber)->first();
        }

        return null;
    }

    /**
     * Extrai o sender ID dos parâmetros
     * 
     * @param array $params
     * @return string|null
     */
    private function extractSenderId(array $params): ?string
    {
        return $params['entry'][0]['changes'][0]['value']['messages'][0]['from'] ?? null;
    }

    /**
     * Extrai o recipient ID dos parâmetros
     * 
     * @param array $params
     * @return string|null
     */
    private function extractRecipientId(array $params): ?string
    {
        return $params['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
    }
}

