<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Current;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware VerifyApiKey
 * 
 * Verifica e valida API keys para acesso programático.
 * Define o account no contexto baseado na API key.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Middleware
 */
class VerifyApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey) {
            return response()->json([
                'error' => 'API Key is required'
            ], 401);
        }

        $key = ApiKey::where('key', $apiKey)->first();

        if (!$key || !$key->isActive()) {
            return response()->json([
                'error' => 'Invalid or inactive API Key'
            ], 401);
        }

        // Define o account no contexto
        Current::setAccount($key->account);

        // Atualiza último uso
        $key->touchLastUsed();

        return $next($request);
    }
}
