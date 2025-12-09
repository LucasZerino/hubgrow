<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * BroadcastServiceProvider
 * 
 * Seguindo a documentação oficial do Laravel:
 * https://laravel.com/docs/12.x/broadcasting#authorizing-channels
 * 
 * O método resolveAuthenticatedUserUsing é chamado pelo BroadcastController
 * para obter o usuário autenticado antes de autorizar os canais.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Conforme documentação do Laravel:
        // https://laravel.com/docs/12.x/broadcasting#authorizing-channels
        // 
        // O resolveAuthenticatedUserUsing é usado para obter o usuário autenticado
        // quando o Laravel precisa autorizar canais privados.
        // 
        // Este callback é chamado quando o guard especificado no canal não conseguiu
        // autenticar automaticamente (ex: quando não há sessão, apenas Bearer token).
        // Aqui autenticamos via Sanctum (Bearer token) e definimos o usuário no guard 'web'.
        Broadcast::resolveAuthenticatedUserUsing(function (Request $request) {
            error_log('[BROADCAST PROVIDER] ========== resolveAuthenticatedUserUsing CHAMADO ==========');
            error_log('[BROADCAST PROVIDER] Path: ' . $request->path());
            
            // Verifica se já está autenticado no guard 'web' (definido pelo middleware)
            $user = \Illuminate\Support\Facades\Auth::guard('web')->user();
            
            if ($user) {
                error_log('[BROADCAST PROVIDER] ✅ Usuário já autenticado no guard web: ID=' . $user->id);
                return $user;
            }
            
            error_log('[BROADCAST PROVIDER] ⚠️ Guard web não tem usuário - tentando autenticar via token');
            
            // Se não está autenticado, tenta autenticar via token
            $token = $request->bearerToken();
            
            if (!$token) {
                $authHeader = $request->header('Authorization');
                if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                    $token = substr($authHeader, 7);
                }
            }
            
            if ($token) {
                $accessToken = PersonalAccessToken::findToken($token);
                
                if ($accessToken) {
                    $user = $accessToken->tokenable;
                    \Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
                    error_log('[BROADCAST PROVIDER] ✅ Usuário autenticado via token: ID=' . $user->id);
                    return $user;
                } else {
                    error_log('[BROADCAST PROVIDER] ❌ Token inválido ou expirado');
                }
            } else {
                error_log('[BROADCAST PROVIDER] ⚠️ Nenhum token fornecido');
            }
            
            error_log('[BROADCAST PROVIDER] ❌ Retornando null - nenhum usuário encontrado');
            return null;
        });
    }
}