<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope para isolamento multi-tenant
 * 
 * Aplica automaticamente filtro por account_id em todas as queries.
 * Garante isolamento completo de dados entre accounts.
 * 
 * @package App\Models\Concerns
 */
class HasAccountScope implements Scope
{
    /**
     * Aplica o scope às queries
     * 
     * @param Builder $builder
     * @param Model $model
     * @return void
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Não aplica scope em tabela de accounts para evitar loops
        if ($model->getTable() === 'accounts') {
            return;
        }

        $account = \App\Support\Current::account();
        
        // Log detalhado quando aplica o scope (especialmente para Inbox)
        if ($model->getTable() === 'inboxes') {
            try {
                \Illuminate\Support\Facades\Log::info('[HasAccountScope] Aplicando scope em Inbox', [
                    'table' => $model->getTable(),
                    'account_found' => $account !== null,
                    'account_id' => $account?->id,
                    'account_name' => $account?->name,
                ]);
            } catch (\Exception $e) {
                // Ignora erros de log para não quebrar a aplicação
            }
        }
        
        if ($account) {
            $whereClause = $model->getTable() . '.account_id = ' . $account->id;
            $builder->where($model->getTable() . '.account_id', $account->id);
            
            // Log para Inbox
            if ($model->getTable() === 'inboxes') {
                \Illuminate\Support\Facades\Log::info('[HasAccountScope] Filtro aplicado', [
                    'where_clause' => $whereClause,
                    'account_id' => $account->id,
                ]);
            }
        } else {
            // Se não houver account definida, não retorna nada (segurança)
            // Isso previne vazamento de dados entre tenants
            $builder->whereRaw('1 = 0');
            
            // Log sempre para debug (não apenas em local)
            try {
                \Illuminate\Support\Facades\Log::error('[HasAccountScope] Account não definida no contexto - BLOQUEANDO QUERY', [
                    'model' => get_class($model),
                    'table' => $model->getTable(),
                    'note' => 'A query será bloqueada (whereRaw 1=0) porque Current::account() é null',
                ]);
            } catch (\Exception $e) {
                // Ignora erros de log para não quebrar a aplicação
            }
        }
    }

    /**
     * Estende queries para incluir relacionamentos sem o scope
     * 
     * @param Builder $builder
     * @return void
     */
    public function extend(Builder $builder): void
    {
        // Permite remover o scope quando necessário
        $builder->macro('withoutAccountScope', function (Builder $builder) {
            return $builder->withoutGlobalScope($this);
        });
    }
}

