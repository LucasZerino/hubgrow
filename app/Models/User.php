<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
        'pubsub_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Relacionamento com Accounts através de AccountUser
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function accountUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    /**
     * Relacionamento com Accounts
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function accounts(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(Account::class, AccountUser::class);
    }

    /**
     * Verifica se o usuário é super admin
     * 
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    /**
     * Verifica se o usuário tem acesso a uma account
     * 
     * @param int $accountId
     * @return bool
     */
    public function hasAccessToAccount(int $accountId): bool
    {
        // Super admin tem acesso a todas as accounts
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->accountUsers()
            ->where('account_id', $accountId)
            ->exists();
    }

    /**
     * Retorna AccountUser para uma account específica
     * 
     * @param int $accountId
     * @return AccountUser|null
     */
    public function getAccountUser(int $accountId): ?AccountUser
    {
        return $this->accountUsers()
            ->where('account_id', $accountId)
            ->first();
    }
}
