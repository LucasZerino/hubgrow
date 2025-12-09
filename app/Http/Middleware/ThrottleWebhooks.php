<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware ThrottleWebhooks
 * 
 * Aplica rate limiting nos webhooks para prevenir sobrecarga.
 * Baseado no padrão do Chatwoot com Rack::Attack.
 * 
 * @package App\Http\Middleware
 */
class ThrottleWebhooks
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param int $maxAttempts Máximo de tentativas
     * @param int $decayMinutes Período em minutos
     * @return Response
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 100, int $decayMinutes = 1): Response
    {
        // Cria chave única baseada no IP e tipo de webhook
        $key = $this->resolveRequestSignature($request);

        // Verifica rate limit
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            return response()->json([
                'error' => 'Too many requests',
                'retry_after' => $seconds
            ], 429)->withHeaders([
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        // Incrementa contador
        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        // Adiciona headers de rate limit
        return $response->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, $maxAttempts),
        ]);
    }

    /**
     * Resolve a chave única para rate limiting
     * 
     * @param Request $request
     * @return string
     */
    protected function resolveRequestSignature(Request $request): string
    {
        $route = $request->route();
        $webhookType = $route->getName() ?? $route->uri();
        $identifier = $route->parameter('phone_number') 
                   ?? $route->parameter('account_id')
                   ?? $request->ip();

        return sprintf('webhook:%s:%s', $webhookType, $identifier);
    }
}

