<?php

namespace App\Models;

use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Contact
 * 
 * Representa um contato (usuário final) que interage com o sistema.
 * Isolado por Account (multi-tenancy).
 * 
 * @package App\Models
 */
class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'name',
        'email',
        'phone_number',
        'identifier_facebook',
        'identifier_instagram',
        'avatar_url',
        'custom_attributes',
        'additional_attributes',
        'last_activity_at',
    ];

    protected $casts = [
        'custom_attributes' => 'array',
        'additional_attributes' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /**
     * Boot do model - aplica global scope e configura deleção em cascata
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new HasAccountScope);

        // Quando um contato é deletado, deleta todas as conversas e contact_inboxes associados
        // Segue o mesmo comportamento do Chatwoot
        static::deleting(function ($contact) {
            // Deleta todas as conversas do contato
            $contact->conversations()->delete();
            
            // Deleta todos os contact_inboxes do contato
            $contact->contactInboxes()->delete();
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
     * Relacionamento com ContactInboxes
     * Quando o contato é deletado, os contact_inboxes também são deletados
     * 
     * @return HasMany
     */
    public function contactInboxes(): HasMany
    {
        return $this->hasMany(ContactInbox::class);
    }

    /**
     * Relacionamento com Conversations
     * Quando o contato é deletado, as conversas também são deletadas (cascata)
     * Segue o mesmo comportamento do Chatwoot
     * 
     * @return HasMany
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
