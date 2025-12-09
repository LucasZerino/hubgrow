<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware HttpTrafficLogger
 * 
 * Registra tráfego HTTP (request/response) no banco de dados.
 * Útil para debugging de webhooks e APIs.
 * 
 * @package App\Http\Middleware
 */
class HttpTrafficLogger
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        // Processa a requisição
        $response = $next($request);
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        // Registra apenas webhooks ou rotas específicas para não floodar o banco
        if ($this->shouldLog($request)) {
            $this->logTraffic($request, $response, $duration);
        }

        return $response;
    }

    /**
     * Verifica se deve logar a requisição
     * 
     * @param Request $request
     * @return bool
     */
    protected function shouldLog(Request $request): bool
    {
        // Loga apenas webhooks do Instagram e Facebook (entrada)
        if ($request->is('api/webhooks/instagram') || $request->is('api/webhooks/instagram/*')) {
            return true;
        }

        if ($request->is('api/webhooks/facebook') || $request->is('api/webhooks/facebook/*')) {
            return true;
        }
        
        return false;
    }

    /**
     * Registra o tráfego no banco
     * 
     * @param Request $request
     * @param Response $response
     * @param float $duration
     * @return void
     */
    protected function logTraffic(Request $request, Response $response, float $duration): void
    {
        try {
            // Tenta extrair account_id se disponível
            $accountId = $request->route('account_id') ?? null;
            
            // Identifica o canal baseado na URL
            $channel = 'unknown';
            if ($request->is('api/webhooks/instagram') || $request->is('api/webhooks/instagram/*')) {
                $channel = 'instagram_webhook';
            } elseif ($request->is('api/webhooks/facebook') || $request->is('api/webhooks/facebook/*')) {
                $channel = 'facebook_webhook';
            } elseif ($request->is('api/webhooks/whatsapp') || $request->is('api/webhooks/whatsapp/*')) {
                $channel = 'whatsapp_webhook';
            }

            $context = [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_headers' => $this->filterHeaders($request->headers->all()),
                'request_body' => $this->truncate($request->getContent()),
                'response_status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'timestamp_br' => now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
            ];

            // Usa o DB direto para performance
            // Tenta usar a tabela public.http_logs se disponível, senão usa app_logs
            $tableName = 'app_logs';
            try {
                // Verifica se a tabela http_logs existe (em um ambiente real, isso seria cacheado ou configurado)
                // Assumindo que o usuário quer http_logs e ela existe
                DB::table('http_logs')->insert([
                    'level' => 'INFO',
                    'message' => "[HTTP TRAFFIC] {$request->method()} {$request->path()} ({$response->getStatusCode()})",
                    'context' => json_encode($context),
                    'account_id' => $accountId,
                    'user_id' => $request->user()?->id,
                    'channel' => $channel,
                    'created_at' => now()->setTimezone('America/Sao_Paulo'),
                ]);
                return; // Sucesso
            } catch (\Exception $e) {
                // Se falhar (ex: tabela não existe), tenta app_logs
                Log::warning('Failed to log to http_logs, trying app_logs: ' . $e->getMessage());
            }

            DB::table('app_logs')->insert([
                'level' => 'INFO',
                'message' => "[HTTP TRAFFIC] {$request->method()} {$request->path()} ({$response->getStatusCode()})",
                'context' => json_encode($context),
                'account_id' => $accountId,
                'user_id' => $request->user()?->id,
                'channel' => $channel,
                'created_at' => now()->setTimezone('America/Sao_Paulo'),
            ]);
            
        } catch (\Exception $e) {
            // Falha silenciosa no logger para não afetar a requisição principal
            Log::warning('Failed to log HTTP traffic: ' . $e->getMessage());
        }
    }

    /**
     * Filtra headers sensíveis
     * 
     * @param array $headers
     * @return array
     */
    protected function filterHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'x-xsrf-token'];
        
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitive)) {
                $headers[$key] = ['***'];
            }
        }
        
        return $headers;
    }

    /**
     * Trunca strings longas
     * 
     * @param string|null $content
     * @param int $length
     * @return string|null
     */
    protected function truncate(?string $content, int $length = 2000): ?string
    {
        if (!$content) {
            return null;
        }
        
        if (strlen($content) > $length) {
            return substr($content, 0, $length) . '... (truncated)';
        }
        
        return $content;
    }
}
