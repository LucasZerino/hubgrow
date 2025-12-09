<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model ContactInbox
 * 
 * Relacionamento entre Contact e Inbox.
 * Armazena o source_id único de cada contato por plataforma.
 * 
 * @package App\Models
 */
class ContactInbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'inbox_id',
        'source_id',
        'hmac_verified',
    ];

    protected $casts = [
        'hmac_verified' => 'array',
    ];

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
     * Relacionamento com Inbox
     * 
     * @return BelongsTo
     */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }
}
