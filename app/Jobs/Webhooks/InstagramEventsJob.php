<?php

namespace App\Jobs\Webhooks;

use App\Exceptions\LockAcquisitionException;
use App\Jobs\MutexJob;
use App\Models\Channel\InstagramChannel;
use App\Services\Instagram\IncomingMessageService;
use App\Support\Current;
use App\Support\Redis\RedisKeys;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job InstagramEventsJob
 * 
 * Processa eventos recebidos via webhook do Instagram.
 * Aplica idempotência através de locks distribuídos.
 * 
 * @package App\Jobs\Webhooks
 */
class InstagramEventsJob extends MutexJob
{
    /**
     * Eventos suportados
     * Similar ao Chatwoot: SUPPORTED_EVENTS = [:message, :read].freeze
     * @var array
     */
    protected const SUPPORTED_EVENTS = ['message', 'read'];

    /**
     * Número máximo de tentativas
     * Similar ao Chatwoot: retry_on LockAcquisitionError, wait: 1.second, attempts: 8
     */
    public $tries = 8;

    /**
     * Backoff exponencial entre tentativas (em segundos)
     * [1, 2, 4, 8, 16, 32, 64, 128]
     */
    public $backoff = [1, 2, 4, 8, 16, 32, 64, 128];

    /**
     * Entries do webhook (armazenados para uso no lock)
     * Similar ao Chatwoot: @entries = entries
     * @var array
     */
    protected array $entries = [];

    /**
     * Parâmetros do webhook
     * IMPORTANTE: Deve ser public para ser serializado corretamente pelo Laravel
     * @var array
     */
    public array $params;

    /**
     * Construtor do job.
     * 
     * IMPORTANTE: No Laravel, quando você faz dispatch($params), os parâmetros
     * são passados para o CONSTRUTOR, não para o handle().
     * O handle() é chamado SEM parâmetros quando o job é deserializado da fila.
     * 
     * @param array $params Parâmetros do webhook
     */
    public function __construct(array $params)
    {
        \Illuminate\Support\Facades\Log::info('[INSTAGRAM WEBHOOK] ====== CONSTRUTOR CHAMADO ======', [
            'params_count' => is_array($params) ? count($params) : 0,
            'has_first_entry' => is_array($params) && isset($params[0]),
        ]);
        
        parent::__construct(); // Inicializa $lockManager
        $this->params = $params;
        
        \Illuminate\Support\Facades\Log::info('[INSTAGRAM WEBHOOK] Construtor finalizado', [
            'params_set' => isset($this->params),
            'params_count' => is_array($this->params) ? count($this->params) : 0,
        ]);
    }

    /**
     * Método chamado após deserialização do job
     * IMPORTANTE: No Laravel, quando um job é deserializado da fila,
     * o construtor NÃO é chamado novamente. Este método pode ser usado
     * para inicializar propriedades após deserialização.
     */
    public function __wakeup(): void
    {
        \Illuminate\Support\Facades\Log::info('[INSTAGRAM WEBHOOK] ====== __WAKEUP CHAMADO ======', [
            'has_params' => isset($this->params),
            'params_count' => isset($this->params) && is_array($this->params) ? count($this->params) : 0,
        ]);
    }

    /**
     * Tempo máximo para retry (em segundos)
     * Após este tempo, o job vai para failed_jobs
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10); // 10 minutos máximo
    }

    /**
     * Execute the job.
     * 
     * IMPORTANTE: No Laravel, quando o job é deserializado da fila, o handle()
     * é chamado SEM parâmetros. Os parâmetros vêm de $this->params que foi
     * definido no construtor e serializado. Isso é o mesmo padrão usado no WebhookJob.
     * 
     * Segue o padrão do Chatwoot:
     * 1. Itera sobre cada entry
     * 2. Para cada entry, itera sobre cada messaging
     * 3. Para cada messaging, encontra o channel e processa o evento
     * 
     * @return void
     */
    public function handle(): void
    {
        // Log ANTES de qualquer coisa para garantir que seja executado
        \Illuminate\Support\Facades\Log::info('[INSTAGRAM WEBHOOK] ====== HANDLE CHAMADO ======', [
            'channel' => 'instagram',
            'has_params' => isset($this->params),
            'params_type' => gettype($this->params ?? null),
            'entries_count' => is_array($this->params) ? count($this->params) : 0,
        ]);
        
        // Usa $this->params que foi definido no construtor e serializado
        $entries = $this->params ?? [];
        $this->entries = $entries;
        
        \Illuminate\Support\Facades\Log::info('[INSTAGRAM WEBHOOK] Entries extraídos', [
            'channel' => 'instagram',
            'entries_count' => is_array($entries) ? count($entries) : 0,
            'has_first_entry' => isset($entries[0]),
        ]);
        
        try {
            // Verifica se entries está definido e não vazio
            if (empty($entries) || !is_array($entries)) {
                \Illuminate\Support\Facades\Log::error('[INSTAGRAM WEBHOOK] Entries vazios ou inválidos no job', [
                    'entries' => $entries,
                ]);
                return;
            }

            // Similar ao Chatwoot: cria lock key baseado em contact_instagram_id e ig_account_id
            $contactInstagramId = $this->getContactInstagramId();
            $igAccountId = $this->getIgAccountId();
            
            if ($contactInstagramId && $igAccountId) {
                $lockKey = sprintf(
                    RedisKeys::INSTAGRAM_MESSAGE_MUTEX,
                    $contactInstagramId,
                    $igAccountId
                );

                Log::info('[INSTAGRAM WEBHOOK] Tentando adquirir lock', [
                    'lock_key' => $lockKey,
                    'contact_instagram_id' => $contactInstagramId,
                    'ig_account_id' => $igAccountId,
                ]);

                try {
                    // Processa com lock distribuído para evitar duplicação
                    // Similar ao Chatwoot: with_lock(key) do { process_entries(entries) end }
                    $this->withLock($lockKey, function () use ($entries) {
                        Log::info('[INSTAGRAM WEBHOOK] Lock adquirido, processando entries');
                        $this->processEntries($entries);
                        Log::info('[INSTAGRAM WEBHOOK] Entries processados com sucesso');
                    });
                } catch (LockAcquisitionException $e) {
                    $attempts = $this->attempts();
                    Log::warning("[INSTAGRAM WEBHOOK] Lock not acquired. Retrying...", [
                        'exception' => $e->getMessage(),
                        'attempt' => $attempts,
                        'max_tries' => $this->tries,
                        'lock_key' => $lockKey,
                    ]);
                    
                    // Se ainda há tentativas, re-enfileira com backoff exponencial
                    if ($attempts < $this->tries) {
                        $backoffSeconds = $this->backoff[$attempts - 1] ?? end($this->backoff);
                        $this->release($backoffSeconds);
                        return;
                    } else {
                        // Máximo de tentativas atingido
                        Log::error("[INSTAGRAM WEBHOOK] Max retries reached for lock: {$lockKey}");
                        throw $e;
                    }
                }
            } else {
                // Se não conseguir extrair IDs, processa sem lock (menos seguro, mas evita falha total)
                Log::warning("[INSTAGRAM WEBHOOK] Could not extract contact_instagram_id or ig_account_id. Processing without lock.", [
                    'contact_instagram_id' => $contactInstagramId,
                    'ig_account_id' => $igAccountId,
                ]);
                $this->processEntries($entries);
            }
        } catch (\Throwable $e) {
            Log::error('[INSTAGRAM WEBHOOK] Erro ao processar job', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'entries_count' => is_array($entries) ? count($entries) : 0,
            ]);
            throw $e; // Re-throw para que o Laravel possa tratar (retry, failed, etc)
        }
    }

    /**
     * Processa todos os entries
     * Similar ao Chatwoot: process_entries
     * 
     * @param array $entries
     * @return void
     */
    protected function processEntries(array $entries): void
    {
        foreach ($entries as $entryIndex => $entry) {
            Log::info("[INSTAGRAM WEBHOOK] Processando entry {$entryIndex}", [
                'entry_keys' => array_keys($entry ?? []),
                'has_messaging' => isset($entry['messaging']),
                'has_standby' => isset($entry['standby']),
                'has_changes' => isset($entry['changes']),
            ]);

            $this->processSingleEntry($entry);
        }
    }

    /**
     * Processa um entry individual
     * Similar ao Chatwoot: process_single_entry
     * 
     * @param array $entry
     * @return void
     */
    protected function processSingleEntry(array $entry): void
    {
        // Verifica se é test event (contém 'changes')
        if ($this->isTestEvent($entry)) {
            Log::info('[INSTAGRAM WEBHOOK] Test event detectado');
            $this->processTestEvent($entry);
            return;
        }

        // Processa mensagens normais
        $this->processMessages($entry);
    }

    /**
     * Processa mensagens de um entry
     * Similar ao Chatwoot: process_messages
     * 
     * @param array $entry
     * @return void
     */
    protected function processMessages(array $entry): void
    {
        $messagings = $this->getMessages($entry);

        if (empty($messagings)) {
            Log::warning('[INSTAGRAM WEBHOOK] Nenhuma messaging encontrada no entry', [
                'entry_keys' => array_keys($entry),
            ]);
            return;
        }

        foreach ($messagings as $messagingIndex => $messaging) {
            Log::info("[INSTAGRAM WEBHOOK] Processando messaging {$messagingIndex}", [
                'messaging_keys' => array_keys($messaging ?? []),
                'has_message' => isset($messaging['message']),
                'has_read' => isset($messaging['read']),
            ]);

            // Extrai instagram_id do messaging (não do entry inteiro)
            // IMPORTANTE: Para mensagens recebidas, recipient.id é nossa conta Instagram
            // Para mensagens echo (enviadas por nós), sender.id é nossa conta Instagram
            $instagramId = $this->extractInstagramIdFromMessaging($messaging);
            
            // Também tenta extrair do entry.id como fallback
            $entryInstagramId = $entry['id'] ?? null;

            if (!$instagramId && !$entryInstagramId) {
                Log::warning("[INSTAGRAM WEBHOOK] Não foi possível extrair Instagram ID do messaging ou entry", [
                    'messaging_keys' => array_keys($messaging ?? []),
                    'entry_keys' => array_keys($entry ?? []),
                    'has_entry_id' => isset($entry['id']),
                    'messaging_structure' => [
                        'has_sender' => isset($messaging['sender']),
                        'has_recipient' => isset($messaging['recipient']),
                        'sender_id' => $messaging['sender']['id'] ?? null,
                        'recipient_id' => $messaging['recipient']['id'] ?? null,
                    ],
                ]);
                continue;
            }

            // Usa instagram_id do messaging, ou fallback para entry.id
            $finalInstagramId = $instagramId ?? $entryInstagramId;

            Log::info("[INSTAGRAM WEBHOOK] Instagram ID extraído", [
                'instagram_id_from_messaging' => $instagramId,
                'instagram_id_from_entry' => $entryInstagramId,
                'final_instagram_id' => $finalInstagramId,
                'is_echo' => isset($messaging['message']['is_echo']),
                'sender_id' => $messaging['sender']['id'] ?? null,
                'recipient_id' => $messaging['recipient']['id'] ?? null,
                'message_mid' => $messaging['message']['mid'] ?? null,
            ]);

            // Encontra channel baseado no instagram_id deste messaging específico
            $channel = $this->findChannel($finalInstagramId);

            // Se não encontrou pelo instagram_id, tenta buscar por todos os channels ativos da account
            // Isso resolve o caso onde o channel ainda tem instagram_id temporário mas o webhook já está chegando
            if (!$channel) {
                Log::warning("[INSTAGRAM WEBHOOK] Channel não encontrado pelo instagram_id, tentando buscar por inbox ativo", [
                    'channel' => 'instagram',
                    'instagram_id_used' => $finalInstagramId,
                    'instagram_id_from_messaging' => $instagramId,
                    'instagram_id_from_entry' => $entryInstagramId,
                    'entry_id' => $entry['id'] ?? null,
                ]);
                
                // Busca todos os channels Instagram ativos (que têm inbox associado)
                // e tenta encontrar um que corresponda ao recipient/sender
                $channel = $this->findChannelByInbox($messaging, $finalInstagramId);
                
                if (!$channel) {
                    // Lista todos os channels Instagram para debug
                    $allChannels = InstagramChannel::withoutGlobalScopes()
                        ->get(['id', 'instagram_id', 'account_id'])
                        ->map(fn($c) => [
                            'id' => $c->id,
                            'instagram_id' => $c->instagram_id,
                            'account_id' => $c->account_id,
                        ])->toArray();
                    
                    Log::info("[INSTAGRAM WEBHOOK] Todos os channels Instagram no sistema", [
                        'channel' => 'instagram',
                        'channels' => $allChannels,
                    ]);
                    
                    continue;
                }
            }

            // Carrega relacionamentos necessários
            if (!$channel->relationLoaded('account')) {
                $channel->load('account');
            }
            
            if (!$channel->relationLoaded('inbox')) {
                $channel->load('inbox');
            }

            if (!$channel->account || !$channel->account->isActive()) {
                Log::warning("[INSTAGRAM WEBHOOK] Account não encontrada ou inativa para este messaging", [
                    'instagram_id' => $instagramId,
                    'channel_id' => $channel->id,
                    'account_id' => $channel->account_id,
                    'account_found' => $channel->account !== null,
                    'account_active' => $channel->account?->isActive() ?? false,
                ]);
                continue;
            }

            if (!$channel->inbox) {
                // Tenta buscar o inbox diretamente do banco
                $inbox = \App\Models\Inbox::where('channel_type', \App\Models\Channel\InstagramChannel::class)
                    ->where('channel_id', $channel->id)
                    ->where('account_id', $channel->account_id)
                    ->first();
                
                if ($inbox) {
                    $channel->setRelation('inbox', $inbox);
                    Log::info("[INSTAGRAM WEBHOOK] Inbox encontrado diretamente no banco e relacionamento forçado", [
                        'channel' => 'instagram',
                        'account_id' => $channel->account_id,
                        'channel_id' => $channel->id,
                        'inbox_id' => $inbox->id,
                    ]);
                } else {
                    Log::warning("[INSTAGRAM WEBHOOK] Channel found but inbox not found", [
                        'channel' => 'instagram',
                        'account_id' => $channel->account_id,
                        'channel_id' => $channel->id,
                        'instagram_id' => $instagramId,
                        'channel_type_expected' => \App\Models\Channel\InstagramChannel::class,
                    ]);
                    continue;
                }
            }

            Log::info('[INSTAGRAM WEBHOOK] Inbox encontrado para este messaging', [
                'channel' => 'instagram',
                'account_id' => $channel->account_id,
                'inbox_id' => $channel->inbox->id,
                'inbox_name' => $channel->inbox->name,
                'channel_id' => $channel->id,
                'instagram_id' => $instagramId,
            ]);

            // Define a conta atual para o escopo global
            Current::setAccount($channel->account);

            // Determina o tipo de evento e processa
            $eventName = $this->getEventName($messaging);

            if ($eventName) {
                Log::info("[INSTAGRAM WEBHOOK] Evento detectado: {$eventName}", [
                    'channel' => 'instagram',
                    'account_id' => $channel->account_id,
                ]);
                $this->processEvent($eventName, $messaging, $channel);
            } else {
                Log::warning("[INSTAGRAM WEBHOOK] Nenhum evento suportado encontrado no messaging", [
                    'messaging_keys' => array_keys($messaging ?? []),
                ]);
            }
        }
    }

    /**
     * Processa um evento específico
     * Similar ao Chatwoot: message() e read()
     * 
     * @param string $eventName
     * @param array $messaging
     * @param InstagramChannel $channel
     * @return void
     */
    protected function processEvent(string $eventName, array $messaging, InstagramChannel $channel): void
    {
        switch ($eventName) {
            case 'message':
                $this->processMessageEvent($messaging, $channel);
                break;

            case 'read':
                $this->processReadEvent($messaging, $channel);
                break;

            default:
                Log::warning("[INSTAGRAM WEBHOOK] Evento não suportado: {$eventName}");
        }
    }

    /**
     * Processa evento de mensagem
     * Similar ao Chatwoot: message()
     * 
     * @param array $messaging
     * @param InstagramChannel $channel
     * @return void
     */
    protected function processMessageEvent(array $messaging, InstagramChannel $channel): void
    {
        Log::info('[INSTAGRAM WEBHOOK] Processando evento de mensagem', [
            'channel' => 'instagram',
            'account_id' => $channel->account_id,
            'messaging_keys' => array_keys($messaging),
            'inbox_id' => $channel->inbox->id,
        ]);

        // Cria estrutura compatível com IncomingMessageService
        // O serviço espera: params[0]['messaging'] = [messaging1, messaging2, ...]
        // Passamos: [['messaging' => [messaging]]]
        try {
            $service = new IncomingMessageService($channel->inbox, [
                [
                    'messaging' => [$messaging]
                ]
            ]);
            $service->process();
            
            Log::info('[INSTAGRAM WEBHOOK] Mensagem processada com sucesso', [
                'channel' => 'instagram',
                'account_id' => $channel->account_id,
            ]);
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM WEBHOOK] Erro ao processar mensagem', [
                'channel' => 'instagram',
                'account_id' => $channel->account_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'inbox_id' => $channel->inbox->id,
            ]);
            throw $e;
        }
    }

    /**
     * Processa evento de read
     * Similar ao Chatwoot: read()
     * 
     * @param array $messaging
     * @param InstagramChannel $channel
     * @return void
     */
    protected function processReadEvent(array $messaging, InstagramChannel $channel): void
    {
        Log::info('[INSTAGRAM WEBHOOK] Processando evento de read');
        // TODO: Implementar processamento de read receipt
        // Similar ao Chatwoot: Instagram::ReadStatusService
    }

    /**
     * Handle a job failure.
     * 
     * Chamado quando o job falha após todas as tentativas.
     * Similar ao dead letter queue do Chatwoot.
     * 
     * @param \Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::error('[INSTAGRAM WEBHOOK] Job falhou após todas as tentativas', [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Aqui você pode:
        // 1. Enviar notificação para admin
        // 2. Salvar em uma tabela de dead letter queue customizada
        // 3. Enviar para um serviço de monitoramento (Sentry, etc.)
        
        // O Laravel já salva automaticamente em failed_jobs
        // Mas podemos adicionar lógica adicional aqui se necessário
    }

    /**
     * Verifica se é um test event
     * Similar ao Chatwoot: test_event?
     * 
     * @param array $entry
     * @return bool
     */
    protected function isTestEvent(array $entry): bool
    {
        return isset($entry['changes']);
    }

    /**
     * Processa test event
     * Similar ao Chatwoot: process_test_event
     * 
     * @param array $entry
     * @return void
     */
    protected function processTestEvent(array $entry): void
    {
        // Extrai messaging do test event
        $messaging = null;
        
        if (isset($entry['changes']) && is_array($entry['changes']) && !empty($entry['changes'])) {
            $messaging = $entry['changes'][0]['value'] ?? null;
        }

        if ($messaging) {
            Log::info('[INSTAGRAM WEBHOOK] Processando test event', [
                'messaging_keys' => array_keys($messaging),
            ]);
            // TODO: Implementar TestEventService similar ao Chatwoot
            // Instagram::TestEventService.new(messaging).perform
        }
    }

    /**
     * Extrai mensagens de um entry
     * Similar ao Chatwoot: messages(entry)
     * Retorna array de messaging (messaging ou standby)
     * Usa .presence do Ruby que retorna null se vazio
     * 
     * @param array $entry
     * @return array
     */
    protected function getMessages(array $entry): array
    {
        // Handle both messaging and standby arrays (como no Chatwoot)
        // Similar ao: (entry[:messaging].presence || entry[:standby] || [])
        if (!empty($entry['messaging'])) {
            return $entry['messaging'];
        }

        if (!empty($entry['standby'])) {
            return $entry['standby'];
        }

        return [];
    }

    /**
     * Extrai Instagram ID de um messaging individual
     * Similar ao Chatwoot: instagram_id(messaging)
     * 
     * @param array $messaging
     * @return string|null
     */
    protected function extractInstagramIdFromMessaging(array $messaging): ?string
    {
        // Se é echo (mensagem enviada por nós), usa sender.id
        if ($this->isAgentMessageViaEcho($messaging)) {
            return $messaging['sender']['id'] ?? null;
        }

        // Para mensagens recebidas, usa recipient.id (nossa conta Instagram)
        return $messaging['recipient']['id'] ?? null;
    }

    /**
     * Verifica se é mensagem de agente via echo
     * Similar ao Chatwoot: agent_message_via_echo?
     * 
     * @param array $messaging
     * @return bool
     */
    protected function isAgentMessageViaEcho(array $messaging): bool
    {
        return isset($messaging['message']['is_echo']) && !empty($messaging['message']['is_echo']);
    }

    /**
     * Determina o nome do evento do messaging
     * Similar ao Chatwoot: event_name(messaging)
     * 
     * @param array $messaging
     * @return string|null
     */
    protected function getEventName(array $messaging): ?string
    {
        foreach (self::SUPPORTED_EVENTS as $event) {
            if (isset($messaging[$event])) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Retorna Instagram Account ID (ig_account_id)
     * Similar ao Chatwoot: ig_account_id
     * 
     * @return string|null
     */
    protected function getIgAccountId(): ?string
    {
        if (empty($this->entries) || !is_array($this->entries)) {
            return null;
        }

        return $this->entries[0]['id'] ?? null;
    }

    /**
     * Retorna Contact Instagram ID (contact_instagram_id)
     * Similar ao Chatwoot: contact_instagram_id
     * 
     * @return string|null
     */
    protected function getContactInstagramId(): ?string
    {
        if (empty($this->entries) || !is_array($this->entries)) {
            return null;
        }

        $entry = $this->entries[0] ?? null;
        if (!$entry) {
            return null;
        }

        // Handle both messaging and standby arrays
        $messaging = null;
        if (!empty($entry['messaging'])) {
            $messaging = $entry['messaging'][0] ?? null;
        } elseif (!empty($entry['standby'])) {
            $messaging = $entry['standby'][0] ?? null;
        }

        if (!$messaging) {
            return null;
        }

        // For echo messages (outgoing from our account), use recipient's ID (the contact)
        // For incoming messages (from contact), use sender's ID (the contact)
        if (!empty($messaging['message']['is_echo'])) {
            return $messaging['recipient']['id'] ?? null;
        }

        return $messaging['sender']['id'] ?? null;
    }

    /**
     * Encontra o canal Instagram pelo instagram_id
     * Similar ao Chatwoot: find_channel(instagram_id)
     * 
     * IMPORTANTE: Verifica tanto InstagramChannel quanto FacebookChannel
     * pois Instagram pode estar conectado via Facebook Page
     * 
     * @param string $instagramId
     * @return InstagramChannel|null
     */
    protected function findChannel(string $instagramId): ?InstagramChannel
    {
        // Prioridade para InstagramChannel (canal criado via login direto do Instagram)
        $channel = InstagramChannel::withoutGlobalScopes()
            ->where('instagram_id', $instagramId)
            ->with(['account', 'inbox']) // Carrega relacionamentos
            ->first();

        // Fallback para FacebookChannel se não encontrou (Instagram conectado via Facebook Page)
        if (!$channel) {
            // TODO: Adicionar busca em FacebookChannel quando implementarmos
            // $channel = FacebookChannel::withoutGlobalScopes()
            //     ->where('instagram_id', $instagramId)
            //     ->with(['account', 'inbox'])
            //     ->first();
        }

        // Se não encontrou inbox via relacionamento, busca diretamente
        if ($channel && !$channel->inbox) {
            $inbox = \App\Models\Inbox::withoutGlobalScopes()
                ->where('channel_type', InstagramChannel::class)
                ->where('channel_id', $channel->id)
                ->where('account_id', $channel->account_id)
                ->first();
            
            if ($inbox) {
                $channel->setRelation('inbox', $inbox);
                Log::info('[INSTAGRAM WEBHOOK] Inbox encontrado diretamente no banco e relacionamento forçado', [
                    'channel_id' => $channel->id,
                    'inbox_id' => $inbox->id,
                ]);
            }
        }

        Log::info('[INSTAGRAM WEBHOOK] Busca de channel realizada', [
            'instagram_id' => $instagramId,
            'channel_found' => $channel !== null,
            'channel_id' => $channel?->id,
            'inbox_id' => $channel?->inbox?->id,
            'account_id' => $channel?->account_id,
            'inbox_loaded' => $channel && $channel->relationLoaded('inbox'),
        ]);

        return $channel;
    }

    /**
     * Busca channel pelo inbox quando o instagram_id não corresponde
     * Útil quando o channel ainda tem instagram_id temporário mas o webhook já está chegando
     * 
     * @param array $messaging
     * @param string $instagramId
     * @return InstagramChannel|null
     */
    protected function findChannelByInbox(array $messaging, string $instagramId): ?InstagramChannel
    {
        // Para mensagens recebidas: recipient.id é nossa conta Instagram
        // Para mensagens echo: sender.id é nossa conta Instagram
        $isEcho = isset($messaging['message']['is_echo']) && !empty($messaging['message']['is_echo']);
        $ourInstagramId = $isEcho 
            ? ($messaging['sender']['id'] ?? null)
            : ($messaging['recipient']['id'] ?? null);
        
        Log::info('[INSTAGRAM WEBHOOK] Tentando encontrar channel pelo inbox', [
            'instagram_id_from_webhook' => $instagramId,
            'our_instagram_id' => $ourInstagramId,
            'is_echo' => $isEcho,
            'sender_id' => $messaging['sender']['id'] ?? null,
            'recipient_id' => $messaging['recipient']['id'] ?? null,
        ]);
        
        // Busca todos os channels Instagram que têm inbox ativo
        $channels = InstagramChannel::withoutGlobalScopes()
            ->with(['account', 'inbox'])
            ->get();
        
        Log::info('[INSTAGRAM WEBHOOK] Channels encontrados no sistema', [
            'total_channels' => $channels->count(),
            'channels' => $channels->map(fn($c) => [
                'id' => $c->id,
                'instagram_id' => $c->instagram_id,
                'account_id' => $c->account_id,
                'has_inbox' => $c->inbox !== null,
                'inbox_id' => $c->inbox?->id,
            ])->toArray(),
        ]);
        
        foreach ($channels as $candidateChannel) {
            // Se o channel tem inbox e account
            if (!$candidateChannel->inbox || !$candidateChannel->account) {
                continue;
            }
            
            // Verifica se o instagram_id do channel corresponde ao nosso ID do webhook
            // OU se o instagram_id do webhook corresponde ao instagram_id do channel
            // OU se o channel é temporário (pode ser atualizado)
            $instagramIdMatches = $candidateChannel->instagram_id === $ourInstagramId || 
                                  $candidateChannel->instagram_id === $instagramId ||
                                  $instagramId === $ourInstagramId;
            
            $isTemporary = str_starts_with($candidateChannel->instagram_id, 'temp_');
            
            if ($instagramIdMatches || $isTemporary) {
                // Se é temporário e temos o instagram_id real do webhook, atualiza
                if ($isTemporary && $ourInstagramId) {
                    Log::info('[INSTAGRAM WEBHOOK] Channel temporário encontrado, atualizando com instagram_id real', [
                        'channel_id' => $candidateChannel->id,
                        'old_instagram_id' => $candidateChannel->instagram_id,
                        'new_instagram_id' => $ourInstagramId,
                    ]);
                    
                    $candidateChannel->update(['instagram_id' => $ourInstagramId]);
                    $candidateChannel->refresh();
                }
                
                Log::info('[INSTAGRAM WEBHOOK] Channel encontrado pelo inbox', [
                    'channel_id' => $candidateChannel->id,
                    'inbox_id' => $candidateChannel->inbox->id,
                    'account_id' => $candidateChannel->account_id,
                    'instagram_id' => $candidateChannel->instagram_id,
                    'matched_by' => $isTemporary ? 'temporary' : ($instagramIdMatches ? 'instagram_id' : 'fallback'),
                ]);
                
                return $candidateChannel;
            }
        }
        
        // Se ainda não encontrou, tenta buscar pelo instagram_id do webhook diretamente
        // (pode haver diferença de tipo ou formatação)
        if ($instagramId) {
            $channel = InstagramChannel::withoutGlobalScopes()
                ->where('instagram_id', $instagramId)
                ->with(['account', 'inbox'])
                ->first();
            
            if ($channel && $channel->inbox && $channel->account) {
                Log::info('[INSTAGRAM WEBHOOK] Channel encontrado pelo instagram_id direto (fallback)', [
                    'channel_id' => $channel->id,
                    'inbox_id' => $channel->inbox->id,
                    'account_id' => $channel->account_id,
                ]);
                return $channel;
            }
        }
        
        return null;
    }

    /**
     * Extrai o sender ID do payload
     * 
     * @param array $params
     * @return string|null
     */
    protected function extractSenderId(array $params): ?string
    {
        // Extrai do primeiro entry > messaging > sender > id
        $entry = $params[0] ?? $params;
        $messaging = $entry['messaging'][0] ?? null;
        
        return $messaging['sender']['id'] ?? null;
    }

    /**
     * Extrai o recipient ID do payload
     * 
     * @param array $params
     * @return string|null
     */
    protected function extractRecipientId(array $params): ?string
    {
        // Extrai do primeiro entry > messaging > recipient > id
        $entry = $params[0] ?? $params;
        $messaging = $entry['messaging'][0] ?? null;
        
        return $messaging['recipient']['id'] ?? null;
    }
}

