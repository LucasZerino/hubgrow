<?php

namespace App\Models;

use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Model Message
 * 
 * Representa uma mensagem dentro de uma conversa.
 * Isolado por Account (multi-tenancy).
 * 
 * @package App\Models
 */
class Message extends Model
{
    use HasFactory;

    /**
     * Tipos de mensagem
     */
    public const TYPE_INCOMING = 0;
    public const TYPE_OUTGOING = 1;
    public const TYPE_ACTIVITY = 2;

    /**
     * Status da mensagem
     */
    public const STATUS_PROGRESS = 0; // Enviando
    public const STATUS_SENT = 1; // Enviada
    public const STATUS_DELIVERED = 2; // Entregue
    public const STATUS_READ = 3; // Lida
    public const STATUS_FAILED = 4; // Falhou

    /**
     * Tipos de conteúdo
     */
    public const CONTENT_TYPE_TEXT = 'text';
    public const CONTENT_TYPE_IMAGE = 'image';
    public const CONTENT_TYPE_VIDEO = 'video';
    public const CONTENT_TYPE_AUDIO = 'audio';
    public const CONTENT_TYPE_FILE = 'file';
    public const CONTENT_TYPE_LOCATION = 'location';
    public const CONTENT_TYPE_CONTACT = 'contact';

    protected $fillable = [
        'account_id',
        'inbox_id',
        'conversation_id',
        'sender_id',
        'sender_type',
        'message_type',
        'content',
        'content_type',
        'source_id',
        'in_reply_to_external_id',
        'status',
        'external_error',
        'content_attributes',
        'private',
    ];

    protected $casts = [
        'content_attributes' => 'array',
        'private' => 'array',
        'message_type' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot do model - aplica global scope
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new HasAccountScope);

        // Dispara eventos quando mensagem é criada
        // IMPORTANTE: Usa created() em vez de created() para garantir que a mensagem está salva
        // Mas pode ser que relacionamentos não estejam carregados ainda
        static::created(function ($message) {
            error_log('[MESSAGE MODEL] ========== EVENTO CREATED DISPARADO ==========');
            error_log('[MESSAGE MODEL] Message ID: ' . $message->id);
            error_log('[MESSAGE MODEL] Account ID: ' . $message->account_id);
            
            \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] ====== EVENTO CREATED DISPARADO ======', [
                'message_id' => $message->id,
                'account_id' => $message->account_id,
                'inbox_id' => $message->inbox_id,
                'conversation_id' => $message->conversation_id,
                'has_inbox_relation' => isset($message->getRelations()['inbox']),
                'inbox_loaded' => $message->relationLoaded('inbox'),
            ]);

            // Carrega relacionamentos necessários ANTES de disparar eventos
            // IMPORTANTE: Relacionamentos podem não estar carregados no momento do created()
            $message->loadMissing(['inbox', 'conversation', 'account', 'sender']);

            \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] Relacionamentos carregados', [
                'message_id' => $message->id,
                'inbox_loaded' => $message->relationLoaded('inbox'),
                'inbox_id_from_relation' => $message->inbox?->id,
                'inbox_name' => $message->inbox?->name,
            ]);

            // NOTA: O evento MessageCreated é disparado pelo MessageObserver
            // Não precisamos disparar aqui para evitar duplicação
            
            // Dispara webhook externo (se configurado)
            if ($message->inbox) {
                error_log('[MESSAGE MODEL] Inbox encontrado, preparando webhook...');
                \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] Preparando webhook externo', [
                    'inbox_id' => $message->inbox->id,
                    'inbox_type' => $message->inbox->channel_type,
                    'channel_id' => $message->inbox->channel_id,
                ]);
                
                try {
                    // Carrega channel se necessário
                    if (!$message->inbox->relationLoaded('channel')) {
                        $message->inbox->load('channel');
                    }
                    
                    $payload = \App\Services\Webhooks\WebhookDispatcher::prepareMessagePayload($message, 'message_created');
                    \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] Payload preparado', [
                        'payload_keys' => array_keys($payload),
                        'payload_event' => $payload['event'] ?? 'unknown',
                    ]);
                    
                    \App\Services\Webhooks\WebhookDispatcher::dispatchForInbox($message->inbox, $payload, 'message_webhook');
                    error_log('[MESSAGE MODEL] Webhook dispatchado com sucesso!');
                    \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] Webhook dispatchado para fila com sucesso');
                } catch (\Exception $e) {
                    error_log('[MESSAGE MODEL] ERRO ao disparar webhook: ' . $e->getMessage());
                    \Illuminate\Support\Facades\Log::error('[MESSAGE MODEL] Erro ao disparar webhook', [
                        'message_id' => $message->id,
                        'inbox_id' => $message->inbox_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            } else {
                error_log('[MESSAGE MODEL] Inbox NÃO encontrado/carregado!');
                \Illuminate\Support\Facades\Log::warning('[MESSAGE MODEL] Inbox não encontrado ou não carregado, webhook não será disparado', [
                    'message_id' => $message->id,
                    'inbox_id' => $message->inbox_id,
                    'inbox_relation_exists' => isset($message->getRelations()['inbox']),
                ]);
            }
            
            \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] ====== FINALIZADO PROCESSAMENTO DO EVENTO CREATED ======');
        });

        // Dispara webhook quando mensagem é atualizada
        static::updated(function ($message) {
            error_log('[MESSAGE MODEL] ========== EVENTO UPDATED DISPARADO ==========');
            error_log('[MESSAGE MODEL] Message ID: ' . $message->id);
            error_log('[MESSAGE MODEL] Campos alterados: ' . implode(', ', array_keys($message->getChanges())));
            error_log('[MESSAGE MODEL] Status mudou? ' . ($message->wasChanged('status') ? 'SIM' : 'NÃO'));
            error_log('[MESSAGE MODEL] Source ID mudou? ' . ($message->wasChanged('source_id') ? 'SIM' : 'NÃO'));
            
            // Carrega relacionamentos necessários antes de disparar webhook
            $message->loadMissing(['inbox', 'conversation', 'account', 'sender']);
            
            if ($message->inbox && $message->wasChanged(['status', 'content', 'source_id'])) {
                error_log('[MESSAGE MODEL] ✅ Campos relevantes mudaram, disparando webhook...');
                \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] Preparando webhook para mensagem atualizada', [
                    'message_id' => $message->id,
                    'inbox_id' => $message->inbox_id,
                    'changed_fields' => array_keys($message->getChanges()),
                ]);
                
                try {
                    // Carrega channel se necessário
                    if (!$message->inbox->relationLoaded('channel')) {
                        $message->inbox->load('channel');
                    }
                    
                $payload = \App\Services\Webhooks\WebhookDispatcher::prepareMessagePayload($message, 'message_updated');
                $payload['changed_attributes'] = $message->getChanges();
                    
                \App\Services\Webhooks\WebhookDispatcher::dispatchForInbox($message->inbox, $payload, 'message_webhook');
                    \Illuminate\Support\Facades\Log::info('[MESSAGE MODEL] Webhook dispatchado para fila com sucesso (updated)');
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('[MESSAGE MODEL] Erro ao disparar webhook (updated)', [
                        'message_id' => $message->id,
                        'inbox_id' => $message->inbox_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        });
    }

    /**
     * Relacionamento com Account
     * 
     * @return BelongsTo
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Relacionamento com Inbox
     * 
     * @return BelongsTo
     */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /**
     * Relacionamento com Conversation
     * 
     * @return BelongsTo
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Relacionamento polimórfico com sender
     * Pode ser User, Contact ou AgentBot
     * Seguindo o padrão do Chatwoot original
     * 
     * @return MorphTo
     */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relacionamento com Attachments
     * 
     * @return HasMany
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Verifica se a mensagem é de entrada
     * 
     * @return bool
     */
    public function isIncoming(): bool
    {
        return $this->message_type === self::TYPE_INCOMING;
    }

    /**
     * Verifica se a mensagem é de saída
     * 
     * @return bool
     */
    public function isOutgoing(): bool
    {
        return $this->message_type === self::TYPE_OUTGOING;
    }

    /**
     * Busca mensagem por source_id (para idempotência)
     * 
     * @param string $sourceId
     * @return self|null
     */
    public static function findBySourceId(string $sourceId): ?self
    {
        return static::where('source_id', $sourceId)->first();
    }
}
