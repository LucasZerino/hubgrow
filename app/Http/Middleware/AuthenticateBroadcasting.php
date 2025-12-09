<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para autenticar requisições de broadcasting via Bearer token
 * 
 * Este middleware autentica o usuário ANTES do BroadcastController processar a requisição,
 * garantindo que o resolveAuthenticatedUserUsing tenha um usuário autenticado disponível.
 */
class AuthenticateBroadcasting
{
    public function handle(Request $request, Closure $next): Response
    {
        // Aplica apenas para rotas de broadcasting
        if (!$request->is('broadcasting/*')) {
            return $next($request);
        }

        // Usa error_log para garantir que apareça no docker logs
        error_log('[BROADCAST MIDDLEWARE] Processando requisição de broadcasting: ' . $request->path() . ' | Method: ' . $request->method() . ' | Has Auth: ' . ($request->hasHeader('Authorization') ? 'YES' : 'NO'));
        
        \Illuminate\Support\Facades\Log::info('[BROADCAST MIDDLEWARE] Processando requisição de broadcasting', [
            'path' => $request->path(),
            'method' => $request->method(),
            'has_auth_header' => $request->hasHeader('Authorization'),
        ]);

        // Se já está autenticado, continua
        if (Auth::check()) {
            \Illuminate\Support\Facades\Log::info('[BROADCAST MIDDLEWARE] Usuário já autenticado', [
                'user_id' => Auth::id(),
            ]);
            return $next($request);
        }

        // Tenta autenticar via Bearer token (Sanctum)
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
                
                // Autentica no guard 'web' para que o resolveAuthenticatedUserUsing possa usar
                Auth::guard('web')->setUser($user);
                
                error_log('[BROADCAST MIDDLEWARE] ✅ Usuário autenticado: ID=' . $user->id . ' | Email=' . $user->email);
                error_log('[BROADCAST MIDDLEWARE] Auth Check após setUser: ' . (Auth::guard('web')->check() ? 'YES' : 'NO'));
                error_log('[BROADCAST MIDDLEWARE] Auth ID após setUser: ' . (Auth::guard('web')->id() ?? 'NULL'));
            }
        }
        
        // Verifica o estado final antes de passar
        error_log('[BROADCAST MIDDLEWARE] Estado final: Auth Check=' . (Auth::guard('web')->check() ? 'YES' : 'NO') . ' | Auth ID=' . (Auth::guard('web')->id() ?? 'NULL'));
        
        return $next($request);
    }
}

