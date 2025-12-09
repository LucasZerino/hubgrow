<?php

namespace App\Models;

use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Model Inbox
 * 
 * Representa uma caixa de entrada que agrupa conversas por canal.
 * Cada canal tem um inbox associado.
 * 
 * @package App\Models
 */
class Inbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'name',
        'channel_type',
        'channel_id',
        'email_address',
        'business_name',
        'timezone',
        'greeting_enabled',
        'greeting_message',
        'out_of_office_message',
        'working_hours_enabled',
        'enable_auto_assignment',
        'auto_assignment_config',
        'allow_messages_after_resolved',
        'lock_to_single_conversation',
        'csat_survey_enabled',
        'csat_config',
        'enable_email_collect',
        'sender_name_type',
        'is_active',
    ];

    protected $casts = [
        'greeting_enabled' => 'boolean',
        'working_hours_enabled' => 'boolean',
        'enable_auto_assignment' => 'boolean',
        'allow_messages_after_resolved' => 'boolean',
        'lock_to_single_conversation' => 'boolean',
        'csat_survey_enabled' => 'boolean',
        'enable_email_collect' => 'boolean',
        'is_active' => 'boolean',
        'auto_assignment_config' => 'array',
        'csat_config' => 'array',
    ];

    /**
     * Boot do model - aplica global scope
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new HasAccountScope);
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
     * Relacionamento polimórfico com Channel
     * 
     * @return MorphTo
     */
    public function channel(): MorphTo
    {
        return $this->morphTo('channel', 'channel_type', 'channel_id');
    }

    /**
     * Relacionamento com Conversations
     * 
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Relacionamento com ContactInboxes
     * 
     * @return HasMany
     */
    public function contactInboxes(): HasMany
    {
        return $this->hasMany(ContactInbox::class);
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
     * Accessor para reauthorization_required
     * Similar ao Chatwoot: resource.channel.try(:reauthorization_required?)
     * 
     * @return bool|null
     */
    public function getReauthorizationRequiredAttribute(): ?bool
    {
        $channel = $this->channel;
        
        if (!$channel) {
            return null;
        }

        // Verifica se o canal tem o método isReauthorizationRequired
        if (method_exists($channel, 'isReauthorizationRequired')) {
            return $channel->isReauthorizationRequired();
        }

        return null;
    }
}
