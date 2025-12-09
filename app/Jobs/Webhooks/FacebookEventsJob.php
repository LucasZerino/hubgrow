<?php

namespace App\Jobs\Webhooks;

use App\Exceptions\LockAcquisitionException;
use App\Jobs\MutexJob;
use App\Models\Channel\FacebookChannel;
use App\Models\Inbox;
use App\Services\Facebook\IncomingMessageService;
use App\Services\Facebook\MessageParser;
use App\Support\Current;
use App\Support\Redis\RedisKeys;
use Illuminate\Support\Facades\Log;

/**
 * Job FacebookEventsJob
 * 
 * Processa eventos recebidos via webhook do Facebook Messenger.
 * Aplica idempotência através de locks distribuídos.
 * 
 * @package App\Jobs\Webhooks
 */
class FacebookEventsJob extends MutexJob
{
    /**
     * Número de tentativas para adquirir lock
     */
    public $tries = 8;

    /**
     * Tempo de espera entre tentativas (em segundos)
     */
    public $backoff = 1;

    /**
     * Payload do webhook (JSON string)
     * @var string
     */
    protected string $payload;

    /**
     * Construtor do job.
     * 
     * @param string $payload Payload do webhook (JSON string).
     */
    public function __construct(string $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     * 
     * @return void
     */
    public function handle(): void
    {
        Log::info('[FACEBOOK JOB] Processando evento', [
            'payload_preview' => substr($this->payload, 0, 200),
        ]);
        
        $parser = new MessageParser($this->payload);
        
        Log::info('[FACEBOOK JOB] Parser criado', [
            'sender_id' => $parser->getSenderId(),
            'recipient_id' => $parser->getRecipientId(),
            'message_id' => $parser->getMessageId(),
            'is_echo' => $parser->isEcho(),
            'is_agent_message' => $parser->isAgentMessageViaEcho(),
        ]);
        
        // Determina se é mensagem de agente (echo) ou contato
        if ($parser->isAgentMessageViaEcho()) {
            Log::info('[FACEBOOK JOB] Processando como mensagem de agente (echo)');
            $this->processAgentMessage($parser);
        } else {
            Log::info('[FACEBOOK JOB] Processando como mensagem de contato');
            $this->processContactMessage($parser);
        }
    }

    /**
     * Processa mensagem de agente (echo)
     * 
     * @param MessageParser $parser
     * @return void
     */
    protected function processAgentMessage(MessageParser $parser): void
    {
        $pageId = $parser->getSenderId();
        
        Log::info('[FACEBOOK JOB] Buscando canal para mensagem de agente', [
            'page_id' => $pageId,
        ]);
        
        $channels = FacebookChannel::withoutGlobalScopes()
            ->where('page_id', $pageId)
            ->get();

        foreach ($channels as $channel) {
            if (!$channel->account || !$channel->account->isActive()) {
                Log::warning("Facebook account not found or inactive for agent message", [
                    'channel_id' => $channel->id,
                    'account_id' => $channel->account_id,
                ]);
                continue;
            }

            // Define a account no contexto ANTES de acessar o relacionamento inbox
            Current::setAccount($channel->account);
            
            // Busca o inbox (com a account definida, o escopo funcionará)
            $inbox = $channel->inbox;
            
            if (!$inbox) {
                Log::warning("Facebook channel found but inbox not found for agent message", [
                    'channel_id' => $channel->id,
                    'account_id' => $channel->account_id,
                ]);
                continue;
            }

            $lockKey = sprintf(
                RedisKeys::FACEBOOK_MESSAGE_MUTEX,
                $parser->getSenderId(),
                $parser->getRecipientId()
            );

            try {
                $this->withLock($lockKey, function () use ($inbox, $parser) {
                    $service = new IncomingMessageService($inbox, $parser);
                    $service->process();
                }, 1);
            } catch (LockAcquisitionException $e) {
                Log::warning("Lock not acquired for Facebook agent message: {$lockKey}. Retrying...", [
                    'exception' => $e->getMessage()
                ]);
                $this->release($this->backoff);
            }
        }
    }

    /**
     * Processa mensagem de contato
     * 
     * @param MessageParser $parser
     * @return void
     */
    protected function processContactMessage(MessageParser $parser): void
    {
        $pageId = $parser->getRecipientId();
        
        Log::info('[FACEBOOK JOB] Buscando canal', [
            'page_id' => $pageId,
            'sender_id' => $parser->getSenderId(),
        ]);
        
        // Busca canal SEM escopo de account primeiro (para encontrar o canal)
        $channel = FacebookChannel::withoutGlobalScopes()
            ->where('page_id', $pageId)
            ->first();

        if (!$channel) {
            Log::warning("Facebook channel not found", [
                'page_id' => $pageId ?? 'unknown',
                'all_page_ids' => FacebookChannel::withoutGlobalScopes()->pluck('page_id')->toArray(),
            ]);
            return;
        }

        // Carrega a account explicitamente
        if (!$channel->relationLoaded('account')) {
            $channel->load('account');
        }

        if (!$channel->account || !$channel->account->isActive()) {
            Log::warning("Facebook account not found or inactive", [
                'page_id' => $pageId,
                'channel_id' => $channel->id,
                'account_id' => $channel->account_id,
                'account_exists' => $channel->account !== null,
                'account_active' => $channel->account?->isActive(),
            ]);
            return;
        }

        Log::info('[FACEBOOK JOB] Channel e account encontrados', [
            'channel_id' => $channel->id,
            'account_id' => $channel->account_id,
            'account_name' => $channel->account->name,
        ]);

        // Define a account no contexto ANTES de buscar o inbox
        // Isso é necessário porque o Inbox também tem HasAccountScope
        Current::setAccount($channel->account);
        
        Log::info('[FACEBOOK JOB] Account definida no contexto', [
            'account_id' => $channel->account->id,
            'account_name' => $channel->account->name,
        ]);

        // Busca o inbox diretamente usando withoutGlobalScopes para evitar problema de escopo
        // Seguindo o padrão do Instagram
        Log::info('[FACEBOOK JOB] Buscando inbox', [
            'channel_id' => $channel->id,
            'channel_type' => FacebookChannel::class,
            'account_id' => $channel->account_id,
        ]);

        $inbox = Inbox::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->where('channel_type', FacebookChannel::class)
            ->first();
        
        Log::info('[FACEBOOK JOB] Resultado da busca do inbox', [
            'inbox_found' => $inbox !== null,
            'inbox_id' => $inbox?->id,
            'inbox_account_id' => $inbox?->account_id,
            'channel_account_id' => $channel->account_id,
        ]);
        
        // Verifica se o inbox pertence à account correta
        if ($inbox && $inbox->account_id !== $channel->account_id) {
            Log::warning("Facebook inbox found but belongs to different account", [
                'page_id' => $pageId,
                'channel_id' => $channel->id,
                'inbox_id' => $inbox->id,
                'channel_account_id' => $channel->account_id,
                'inbox_account_id' => $inbox->account_id,
            ]);
            $inbox = null;
        }
        
        if (!$inbox) {
            // Tenta buscar todos os inboxes para debug
            $allInboxes = Inbox::withoutGlobalScopes()
                ->where('channel_type', FacebookChannel::class)
                ->get(['id', 'channel_id', 'account_id', 'name']);
            
            Log::warning("Facebook channel found but inbox not found", [
                'page_id' => $pageId,
                'channel_id' => $channel->id,
                'account_id' => $channel->account_id,
                'all_inboxes_count' => $allInboxes->count(),
                'all_inboxes' => $allInboxes->toArray(),
            ]);
            return;
        }

        Log::info('[FACEBOOK JOB] Canal e inbox encontrados', [
            'channel_id' => $channel->id,
            'inbox_id' => $inbox->id,
            'account_id' => $channel->account->id,
        ]);

        $lockKey = sprintf(
            RedisKeys::FACEBOOK_MESSAGE_MUTEX,
            $parser->getSenderId(),
            $parser->getRecipientId()
        );

        try {
            $this->withLock($lockKey, function () use ($inbox, $parser) {
                $service = new IncomingMessageService($inbox, $parser);
                $service->process();
            }, 1);
        } catch (LockAcquisitionException $e) {
            Log::warning("Lock not acquired for Facebook message: {$lockKey}. Retrying...", [
                'exception' => $e->getMessage()
            ]);
            $this->release($this->backoff);
        }
    }
}

