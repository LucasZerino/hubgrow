<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware customizado para CORS
 * Garante que preflight requests (OPTIONS) sejam tratados corretamente
 */
class HandleCorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // LOG ABSOLUTAMENTE TUDO que chega no middleware
        error_log('[CORS MIDDLEWARE] ========== MIDDLEWARE ENTRY ==========');
        error_log('[CORS MIDDLEWARE] Path: ' . $request->path());
        error_log('[CORS MIDDLEWARE] Full URL: ' . $request->fullUrl());
        error_log('[CORS MIDDLEWARE] Method: ' . $request->method());
        error_log('[CORS MIDDLEWARE] Origin: ' . ($request->header('Origin') ?: 'NULL'));
        error_log('[CORS MIDDLEWARE] IP: ' . $request->ip());
        error_log('[CORS MIDDLEWARE] User Agent: ' . ($request->header('User-Agent') ?: 'NULL'));
        
        // Não aplica CORS em rotas de broadcasting - deixa o Laravel/Pusher lidar com isso
        // O broadcasting usa autenticação via POST com dados específicos do Pusher
        if (str_starts_with($request->path(), 'broadcasting/')) {
            return $next($request);
        }
        
        $origin = $this->getAllowedOrigin($request);
        
        // Responde ao preflight request (OPTIONS) ANTES de qualquer outro processamento
        if ($request->getMethod() === 'OPTIONS') {
            error_log('[CORS MIDDLEWARE] OPTIONS PREFLIGHT REQUEST');
            Log::info('[CORS] Preflight request', [
                'path' => $request->path(),
                'origin' => $origin,
            ]);
            $response = response('', 204);
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');
            return $response;
        }

        error_log('[CORS MIDDLEWARE] CONTINUING WITH REQUEST');
        Log::info('[CORS] ========== REQUEST RECEIVED ==========', [
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'method' => $request->method(),
            'origin' => $origin,
            'query' => $request->query->all(),
            'headers' => [
                'authorization' => $request->header('Authorization') ? 'PRESENT' : 'MISSING',
                'accept' => $request->header('Accept'),
            ],
        ]);
        
        // Log específico para conversations
        if (str_contains($request->path(), 'conversations')) {
            Log::info('[CORS] ========== CONVERSATIONS REQUEST ==========', [
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'all_param' => $request->get('all'),
                'limit_param' => $request->get('limit'),
                'all_params' => $request->all(),
            ]);
        }

        // Para outras requisições, adiciona headers CORS e continua
        Log::info('[CORS] Calling next middleware/controller', ['path' => $request->path()]);
        
        try {
            $response = $next($request);
            Log::info('[CORS] Next middleware/controller returned', [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
            ]);
        } catch (\Exception $e) {
            Log::error('[CORS] ========== ERROR IN REQUEST ==========', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        // Adiciona headers CORS na resposta
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        // Força envio dos headers imediatamente
        if (function_exists('fastcgi_finish_request')) {
            // Em FastCGI, garante que os headers sejam enviados
            $response->sendHeaders();
        }

        Log::info('[CORS] Response sent', [
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'content_length' => $response->headers->get('Content-Length', strlen($response->getContent())),
            'headers' => $response->headers->all(),
        ]);

        return $response;
    }

    /**
     * Obtém origem permitida baseado na requisição
     */
    protected function getAllowedOrigin(Request $request): string
    {
        $origin = $request->headers->get('Origin');
        
        // Lista de origens permitidas (inclui FRONTEND_URL do .env)
        $allowedOrigins = array_filter([
            env('FRONTEND_URL', 'http://localhost:3005'),
            'http://localhost:3005', // Next.js GrowHub
            'http://localhost:5173',
            'http://localhost:3000',
            'http://localhost:3002',
            'http://127.0.0.1:3005',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3002',
        ]);

        // Se não há origem na requisição, retorna a primeira permitida
        if (!$origin) {
            return $allowedOrigins[0] ?? '*';
        }

        // Se a origem está na lista, permite
        if (in_array($origin, $allowedOrigins, true)) {
            return $origin;
        }

        // Para desenvolvimento, permite qualquer origem localhost
        if (str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
            return $origin;
        }

        // Fallback: retorna a primeira origem permitida
        return $allowedOrigins[0] ?? '*';
    }
}

