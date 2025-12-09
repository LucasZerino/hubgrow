<?php

use Illuminate\Support\Facades\Broadcast;

/**
 * Rotas de Broadcast (WebSocket Channels)
 * 
 * Define canais privados e valida acesso.
 * Garante isolamento entre accounts - cada account só recebe seus próprios eventos.
 */

// Canal privado para eventos de uma account específica
// O frontend conecta em: private-account.{accountId}
// O Laravel valida se o usuário autenticado tem acesso à account
Broadcast::channel('account.{accountId}', function ($user, int $accountId) {
    // Log para debug - verifica se o usuário está chegando
    \Illuminate\Support\Facades\Log::info('[BROADCAST CHANNEL] Tentativa de autorização', [
        'has_user' => !!$user,
        'user_id' => $user?->id,
        'user_email' => $user?->email,
        'account_id' => $accountId,
        'auth_check' => \Illuminate\Support\Facades\Auth::check(),
        'auth_id' => \Illuminate\Support\Facades\Auth::id(),
    ]);
    
    // Verifica se o usuário está autenticado
    if (!$user) {
        \Illuminate\Support\Facades\Log::warning('[BROADCAST CHANNEL] ❌ Usuário não autenticado - negando acesso');
        return false;
    }

    // Verifica se o usuário tem acesso à account
    // Super admin tem acesso a todas as accounts
    if ($user->isSuperAdmin()) {
        \Illuminate\Support\Facades\Log::info('[BROADCAST CHANNEL] ✅ Super admin autorizado', [
            'user_id' => $user->id,
            'account_id' => $accountId,
        ]);
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account_id' => $accountId,
        ];
    }

    // Usuário comum precisa ter acesso à account
    if ($user->hasAccessToAccount($accountId)) {
        \Illuminate\Support\Facades\Log::info('[BROADCAST CHANNEL] ✅ Usuário autorizado', [
            'user_id' => $user->id,
            'account_id' => $accountId,
        ]);
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account_id' => $accountId,
        ];
    }

    // Sem acesso, nega conexão
    \Illuminate\Support\Facades\Log::warning('[BROADCAST CHANNEL] ❌ Usuário sem acesso à account', [
        'user_id' => $user->id,
        'account_id' => $accountId,
    ]);
    return false;
});

// Canal privado para usuário específico (opcional, para notificações pessoais)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

