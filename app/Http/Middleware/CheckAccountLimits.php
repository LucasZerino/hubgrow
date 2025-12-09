<?php

namespace App\Http\Middleware;

use App\Support\Current;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware CheckAccountLimits
 * 
 * Verifica limites da account antes de criar recursos.
 * 
 * @package App\Http\Middleware
 */
class CheckAccountLimits
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $resource, ?string $channelType = null): Response
    {
        $account = Current::account();

        if (!$account) {
            return response()->json(['error' => 'Account não encontrada'], 404);
        }

        // Super admin não tem limites
        $user = Current::user();
        if ($user && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Verifica se pode criar o recurso
        if (!$account->canCreateResource($resource, $channelType)) {
            $usage = $account->getResourceUsage($resource, $channelType);
            
            return response()->json([
                'error' => 'Limite de recursos excedido',
                'message' => "Você atingiu o limite de {$resource} para esta conta.",
                'usage' => $usage,
            ], 402); // 402 Payment Required
        }

        return $next($request);
    }
}

