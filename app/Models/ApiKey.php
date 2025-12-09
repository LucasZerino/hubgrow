<?php

namespace App\Models;

use App\Models\Concerns\HasAccountScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Model ApiKey
 * 
 * Representa uma chave de API para acesso programático.
 * Usado para venda da API (multi-tenant).
 * 
 * @package App\Models
 */
class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'name',
        'key',
        'scopes',
        'rate_limit',
        'last_used_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'scopes' => 'array',
        'rate_limit' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Boot do model - gera key automaticamente
     */
    protected static function booted(): void
    {
        static::creating(function ($apiKey) {
            if (empty($apiKey->key)) {
                $apiKey->key = Str::random(64);
            }
        });

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
     * Verifica se a key está ativa
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    /**
     * Verifica se a key expirou
     * 
     * @return bool
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Atualiza o timestamp de último uso
     * 
     * @return void
     */
    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
