<?php

namespace App\Services\WhatsApp;

use App\Models\Inbox;
use App\Models\Message;
use App\Services\Base\IdempotencyTrait;
use App\Services\Base\IncomingMessageServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Serviço IncomingMessageService
 * 
 * Processa mensagens recebidas do WhatsApp.
 * Aplica idempotência e garante processamento único.
 * Segue o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class IncomingMessageService implements IncomingMessageServiceInterface
{
    use IdempotencyTrait;

    protected Inbox $inbox;
    protected array $params;
    protected array $processedParams;

    /**
     * Construtor
     * 
     * @param Inbox $inbox
     * @param array $params
     */
    public function __construct(Inbox $inbox, array $params)
    {
        $this->inbox = $inbox;
        $this->params = $params;
        $this->processedParams = $this->extractProcessedParams();
    }

    /**
     * Processa a mensagem recebida
     * 
     * @return void
     */
    public function process(): void
    {
        // Verifica se é um status update ou uma mensagem
        if (isset($this->processedParams['statuses'])) {
            $this->processStatuses();
            return;
        }

        if (!isset($this->processedParams['messages'])) {
            return;
        }

        $message = $this->processedParams['messages'][0];
        $sourceId = $message['id'] ?? null;

        if (!$sourceId) {
            Log::warning('WhatsApp message without source_id', $this->params);
            return;
        }

        // Verifica idempotência
        if ($this->isMessageProcessed($sourceId)) {
            Log::info('WhatsApp message already processed', ['source_id' => $sourceId]);
            return;
        }

        if ($this->isMessageUnderProcess($sourceId)) {
            Log::info('WhatsApp message already being processed', ['source_id' => $sourceId]);
            return;
        }

        // Marca como em processamento
        $this->markMessageAsProcessing($sourceId);

        try {
            DB::transaction(function () use ($message, $sourceId) {
                $this->processMessage($message, $sourceId);
            });
        } finally {
            $this->clearMessageProcessing($sourceId);
        }
    }

    /**
     * Processa uma mensagem
     * 
     * @param array $message
     * @param string $sourceId
     * @return void
     */
    protected function processMessage(array $message, string $sourceId): void
    {
        // TODO: Implementar lógica completa de processamento
        // - Identificar/criar contato
        // - Identificar/criar conversa
        // - Criar mensagem
        // - Processar anexos se houver
        
        Log::info('Processing WhatsApp message', [
            'source_id' => $sourceId,
            'inbox_id' => $this->inbox->id
        ]);
    }

    /**
     * Processa atualizações de status
     * 
     * @return void
     */
    protected function processStatuses(): void
    {
        $status = $this->processedParams['statuses'][0];
        $sourceId = $status['id'] ?? null;

        if (!$sourceId) {
            return;
        }

        $message = Message::findBySourceId($sourceId);

        if ($message) {
            $message->update([
                'status' => $this->mapStatus($status['status']),
                'external_error' => $status['errors'][0]['title'] ?? null,
            ]);
        }
    }

    /**
     * Extrai parâmetros processados do payload
     * 
     * @return array
     */
    protected function extractProcessedParams(): array
    {
        // Para WhatsApp Cloud API
        if (isset($this->params['entry'][0]['changes'][0]['value'])) {
            return $this->params['entry'][0]['changes'][0]['value'];
        }

        return $this->params;
    }

    /**
     * Mapeia status do WhatsApp para status interno
     * 
     * @param string $whatsappStatus
     * @return int
     */
    protected function mapStatus(string $whatsappStatus): int
    {
        return match ($whatsappStatus) {
            'sent' => Message::STATUS_SENT,
            'delivered' => Message::STATUS_DELIVERED,
            'read' => Message::STATUS_READ,
            'failed' => Message::STATUS_FAILED,
            default => Message::STATUS_SENT,
        };
    }
}

