<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Serviço WebhookTriggerService
 * 
 * Envia eventos via webhook para URLs externas (frontend).
 * Implementa idempotência e retry logic similar ao Chatwoot.
 * 
 * @package App\Services\Webhooks
 */
class WebhookTriggerService
{
    /**
     * Timeout padrão para requisições webhook (em segundos)
     */
    protected const DEFAULT_TIMEOUT = 5;

    /**
     * Tempo de cache para idempotência (em segundos)
     * Previne envio duplicado do mesmo evento
     */
    protected const IDEMPOTENCY_CACHE_TTL = 3600; // 1 hora

    /**
     * Executa o envio do webhook
     * 
     * @param string $url URL do webhook
     * @param array $payload Dados do evento
     * @param string|null $webhookType Tipo do webhook (para logs)
     * @return bool Sucesso do envio
     */
    public static function execute(string $url, array $payload, ?string $webhookType = null): bool
    {
        $service = new self();
        return $service->send($url, $payload, $webhookType);
    }

    /**
     * Envia o webhook
     * 
     * @param string $url
     * @param array $payload
     * @param string|null $webhookType
     * @return bool
     */
    protected function send(string $url, array $payload, ?string $webhookType = null): bool
    {
        Log::info('[WEBHOOK TRIGGER] Iniciando envio de webhook', [
            'url' => $this->maskUrl($url),
            'event' => $payload['event'] ?? 'unknown',
            'type' => $webhookType,
        ]);

        // Valida URL
        if (!$this->isValidUrl($url)) {
            Log::warning('[WEBHOOK TRIGGER] Invalid URL', [
                'url' => $url,
                'type' => $webhookType,
            ]);
            return false;
        }

        // Verifica idempotência
        $idempotencyKey = $this->generateIdempotencyKey($url, $payload);
        if ($this->isDuplicate($idempotencyKey)) {
            Log::info('[WEBHOOK TRIGGER] Duplicate webhook ignored', [
                'url' => $this->maskUrl($url),
                'type' => $webhookType,
                'event' => $payload['event'] ?? 'unknown',
                'idempotency_key' => $idempotencyKey,
            ]);
            return true; // Retorna true pois já foi enviado
        }

        Log::info('[WEBHOOK TRIGGER] Enviando requisição HTTP', [
            'url' => $this->maskUrl($url),
            'timeout' => $this->getTimeout(),
        ]);

        $startTime = microtime(true);
        
        try {
            $timeout = $this->getTimeout();
            
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Webhook-Idempotency-Key' => $idempotencyKey,
                ])
                ->post($url, $payload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log no banco de dados (http_logs)
            try {
                $context = [
                    'method' => 'POST',
                    'url' => $url,
                    'request_body' => json_encode($payload),
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'duration_ms' => $duration,
                    'timestamp_br' => now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
                    'webhook_type' => $webhookType,
                    'event' => $payload['event'] ?? 'unknown',
                ];

                DB::table('http_logs')->insert([
                    'level' => $response->successful() ? 'INFO' : 'ERROR',
                    'message' => "[WEBHOOK OUTGOING] POST {$url} ({$response->status()})",
                    'context' => json_encode($context),
                    'account_id' => $payload['account']['id'] ?? null,
                    'channel' => 'webhook_outgoing',
                    'created_at' => now()->setTimezone('America/Sao_Paulo'),
                ]);
            } catch (\Exception $e) {
                Log::error('[WEBHOOK TRIGGER] Erro ao salvar log no banco', [
                    'error' => $e->getMessage()
                ]);
            }

            Log::info('[WEBHOOK TRIGGER] Resposta recebida', [
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            if ($response->successful()) {
                // Marca como enviado para idempotência
                $this->markAsSent($idempotencyKey);
                
                Log::info('[WEBHOOK TRIGGER] Webhook sent successfully', [
                    'url' => $this->maskUrl($url),
                    'type' => $webhookType,
                    'event' => $payload['event'] ?? 'unknown',
                    'status' => $response->status(),
                ]);
                
                return true;
            } else {
                Log::warning('[WEBHOOK TRIGGER] Webhook failed', [
                    'url' => $this->maskUrl($url),
                    'type' => $webhookType,
                    'event' => $payload['event'] ?? 'unknown',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                
                return false;
            }
        } catch (\Exception $e) {
            Log::error('[WEBHOOK TRIGGER] Webhook exception', [
                'url' => $this->maskUrl($url),
                'type' => $webhookType,
                'event' => $payload['event'] ?? 'unknown',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        }
    }

    /**
     * Verifica se a URL é do nosso frontend interno
     * 
     * @param string $url
     * @return bool
     */
    protected function isInternalFrontendUrl(string $url): bool
    {
        $frontendUrl = config('app.frontend_url') ?? env('FRONTEND_URL');
        if (!$frontendUrl) {
            return false;
        }

        $parsed = parse_url($url);
        $parsedFrontend = parse_url($frontendUrl);

        // Compara host (sem porta)
        $host = $parsed['host'] ?? '';
        $frontendHost = $parsedFrontend['host'] ?? '';

        // Remove porta se houver
        $host = preg_replace('/:\d+$/', '', $host);
        $frontendHost = preg_replace('/:\d+$/', '', $frontendHost);

        return $host === $frontendHost || str_ends_with($host, $frontendHost);
    }

    /**
     * Envia webhook para endpoint interno (não via HTTP externo)
     * 
     * @param string $url
     * @param array $payload
     * @param string|null $webhookType
     * @return bool
     */
    protected function sendToInternalEndpoint(string $url, array $payload, ?string $webhookType = null): bool
    {
        try {
            // Extrai inbox_id do payload
            $inboxId = $payload['inbox']['id'] ?? null;
            if (!$inboxId) {
                Log::warning('[WEBHOOK] No inbox_id in payload for internal endpoint');
                return false;
            }

            // Chama diretamente o controller interno
            $controller = new \App\Http\Controllers\Webhooks\FrontendWebhookController();
            $request = \Illuminate\Http\Request::create('/api/webhooks/frontend/events', 'POST', $payload);
            $response = $controller->receive($request);

            if ($response->getStatusCode() === 200) {
                Log::info('[WEBHOOK] Internal webhook sent successfully', [
                    'inbox_id' => $inboxId,
                    'event' => $payload['event'] ?? 'unknown',
                ]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('[WEBHOOK] Internal webhook exception', [
                'url' => $this->maskUrl($url),
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Valida se a URL é válida
     * 
     * @param string $url
     * @return bool
     */
    protected function isValidUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        $parsed = parse_url($url);
        return isset($parsed['scheme']) 
            && in_array($parsed['scheme'], ['http', 'https'])
            && isset($parsed['host']);
    }

    /**
     * Gera chave de idempotência baseada na URL e payload
     * 
     * @param string $url
     * @param array $payload
     * @return string
     */
    protected function generateIdempotencyKey(string $url, array $payload): string
    {
        // Usa URL + evento + ID do recurso (se disponível) para gerar chave única
        $event = $payload['event'] ?? 'unknown';
        $resourceId = $payload['id'] ?? $payload['message']['id'] ?? $payload['conversation']['id'] ?? null;
        
        $key = sprintf('webhook:%s:%s', md5($url), $event);
        if ($resourceId) {
            $key .= ':' . $resourceId;
        }
        
        return $key;
    }

    /**
     * Verifica se o webhook já foi enviado (idempotência)
     * 
     * @param string $idempotencyKey
     * @return bool
     */
    protected function isDuplicate(string $idempotencyKey): bool
    {
        return Cache::has($idempotencyKey);
    }

    /**
     * Marca o webhook como enviado
     * 
     * @param string $idempotencyKey
     * @return void
     */
    protected function markAsSent(string $idempotencyKey): void
    {
        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_CACHE_TTL);
    }

    /**
     * Retorna timeout configurado ou padrão
     * 
     * @return int
     */
    protected function getTimeout(): int
    {
        $timeout = config('services.webhook.timeout');
        return $timeout && $timeout > 0 ? (int) $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * Mascara URL para logs (oculta partes sensíveis)
     * 
     * @param string $url
     * @return string
     */
    protected function maskUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return 'invalid-url';
        }

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        
        // Mascara query string se houver
        $query = isset($parsed['query']) ? '?***' : '';
        
        return sprintf('%s://%s%s%s', $scheme, $host, $path, $query);
    }
}

