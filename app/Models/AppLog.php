<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model AppLog
 * 
 * Armazena logs da aplicação no banco de dados.
 * Permite busca, filtros e análise de logs.
 * 
 * @package App\Models
 */
class AppLog extends Model
{
    /**
     * Nome da tabela
     * 
     * @var string
     */
    protected $table = 'app_logs';

    /**
     * Campos que podem ser preenchidos em massa
     * 
     * @var array
     */
    protected $fillable = [
        'level',
        'message',
        'context',
        'account_id',
        'user_id',
        'channel',
    ];

    /**
     * Casts
     * 
     * @var array
     */
    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Não usar timestamps (apenas created_at)
     * 
     * @var bool
     */
    public $timestamps = false;

    /**
     * Scope: Filtrar por nível
     * 
     * @param Builder $query
     * @param string $level
     * @return Builder
     */
    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    /**
     * Scope: Filtrar por account
     * 
     * @param Builder $query
     * @param int $accountId
     * @return Builder
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope: Filtrar por canal
     * 
     * @param Builder $query
     * @param string $channel
     * @return Builder
     */
    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope: Últimas N horas
     * 
     * @param Builder $query
     * @param int $hours
     * @return Builder
     */
    public function scopeLastHours(Builder $query, int $hours): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: Buscar por texto na mensagem
     * 
     * @param Builder $query
     * @param string $search
     * @return Builder
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where('message', 'ILIKE', "%{$search}%");
    }
}
