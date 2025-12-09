<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model AccountUser
 * 
 * Relacionamento many-to-many entre Users e Accounts.
 * Define o papel (role) do usuário em cada account.
 * 
 * @package App\Models
 */
class AccountUser extends Model
{
    use HasFactory;

    /**
     * Roles possíveis
     */
    public const ROLE_AGENT = 0;
    public const ROLE_ADMINISTRATOR = 1;

    protected $fillable = [
        'account_id',
        'user_id',
        'role',
        'active_at',
    ];

    protected $casts = [
        'role' => 'integer',
        'active_at' => 'datetime',
    ];

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
     * Relacionamento com User
     * 
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Verifica se o usuário é agente
     * 
     * @return bool
     */
    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
    }

    /**
     * Verifica se o usuário é administrador
     * 
     * @return bool
     */
    public function isAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMINISTRATOR;
    }
}
