<?php

namespace App\Models;

use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Conversation
 * 
 * Representa uma conversa entre um contato e agentes.
 * Isolado por Account (multi-tenancy).
 * 
 * @package App\Models
 */
class Conversation extends Model
{
    use HasFactory;

    /**
     * Status possíveis da conversa
     */
    public const STATUS_OPEN = 0;
    public const STATUS_RESOLVED = 1;
    public const STATUS_PENDING = 2;

    /**
     * Prioridades possíveis
     */
    public const PRIORITY_LOW = 0;
    public const PRIORITY_MEDIUM = 1;
    public const PRIORITY_HIGH = 2;
    public const PRIORITY_URGENT = 3;

    protected $fillable = [
        'account_id',
        'inbox_id',
        'contact_id',
        'contact_inbox_id',
        'display_id',
        'status',
        'priority',
        'assignee_id',
        'last_activity_at',
        'snoozed_until',
        'custom_attributes',
        'additional_attributes',
    ];

    protected $casts = [
        'custom_attributes' => 'array',
        'additional_attributes' => 'array',
        'last_activity_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'status' => 'integer',
        'priority' => 'integer',
    ];

    /**
     * Boot do model - aplica global scope
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new HasAccountScope);

        // Dispara eventos quando conversa é criada
        static::created(function ($conversation) {
            // Dispara evento de broadcast via WebSocket
            try {
                \App\Events\ConversationCreated::dispatch($conversation);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('[CONVERSATION MODEL] Erro ao disparar evento ConversationCreated', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Dispara webhook externo (se configurado)
            if ($conversation->inbox) {
                $payload = \App\Services\Webhooks\WebhookDispatcher::prepareConversationPayload($conversation, 'conversation_created');
                \App\Services\Webhooks\WebhookDispatcher::dispatchForInbox($conversation->inbox, $payload, 'conversation_webhook');
            }
        });

        // Dispara webhook quando conversa é atualizada
        static::updated(function ($conversation) {
            if ($conversation->inbox && $conversation->wasChanged(['status', 'priority', 'assignee_id'])) {
                $payload = \App\Services\Webhooks\WebhookDispatcher::prepareConversationPayload($conversation, 'conversation_updated');
                $payload['changed_attributes'] = $conversation->getChanges();
                \App\Services\Webhooks\WebhookDispatcher::dispatchForInbox($conversation->inbox, $payload, 'conversation_webhook');
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
     * Relacionamento com Contact
     * 
     * @return BelongsTo
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Relacionamento com ContactInbox
     * 
     * @return BelongsTo
     */
    public function contactInbox(): BelongsTo
    {
        return $this->belongsTo(ContactInbox::class);
    }

    /**
     * Relacionamento com Assignee (User)
     * 
     * @return BelongsTo
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Relacionamento com Messages
     * 
     * @return HasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Verifica se a conversa está aberta
     * 
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Verifica se a conversa está resolvida
     * 
     * @return bool
     */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }
}
