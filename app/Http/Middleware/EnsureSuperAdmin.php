<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware EnsureSuperAdmin
 * 
 * Garante que apenas super admins possam acessar a rota.
 * 
 * @package App\Http\Middleware
 */
class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isSuperAdmin()) {
            return response()->json([
                'error' => 'Acesso negado. Apenas super administradores podem acessar este recurso.',
            ], 403);
        }

        return $next($request);
    }
}
