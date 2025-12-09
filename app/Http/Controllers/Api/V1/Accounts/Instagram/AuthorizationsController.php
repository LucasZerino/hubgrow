<?php

namespace App\Http\Controllers\Api\V1\Accounts\Instagram;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Services\Instagram\OAuthCallbackService;
use App\Services\Instagram\InstagramOAuthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller AuthorizationsController
 * 
 * Gerencia autorização OAuth do Instagram.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\Instagram
 */
class AuthorizationsController extends BaseController
{
    /**
     * Gera URL de autorização OAuth
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        // Verifica se Instagram está configurado
        $isConfigured = \App\Support\AppConfigHelper::isConfigured('instagram');
        
        if (!$isConfigured) {
            // Debug: buscar configuração para ver o que está errado
            $config = \App\Support\AppConfigHelper::getConfig('instagram');
            $debug = [];
            
            if ($config) {
                $debug = [
                    'found' => true,
                    'is_active' => $config->is_active,
                    'app_name' => $config->app_name,
                    'has_credentials' => !empty($config->credentials),
                    'credentials_keys' => array_keys($config->credentials ?? []),
                    'app_id_exists' => isset($config->credentials['app_id']),
                    'app_id_value' => !empty($config->credentials['app_id'] ?? ''),
                    'app_secret_exists' => isset($config->credentials['app_secret']),
                    'app_secret_value' => !empty($config->credentials['app_secret'] ?? ''),
                ];
            } else {
                $debug = [
                    'found' => false,
                    'message' => 'Nenhuma configuração encontrada com app_name="instagram"',
                ];
            }
            
            return response()->json([
                'error' => 'Instagram não configurado',
                'message' => 'O SuperAdmin precisa configurar as credenciais do Instagram antes de criar canais.',
                'debug' => $debug,
            ], 503);
        }

        // Verifica limite de canais Instagram
        $account = $this->account;
        if (!$account->canCreateResource('instagram_channels')) {
            $usage = $account->getResourceUsage('instagram_channels');
            return response()->json([
                'error' => 'Limite de canais Instagram excedido',
                'message' => 'Você atingiu o limite de canais Instagram para esta conta.',
                'usage' => $usage,
            ], 402);
        }

        $request->validate([
            'redirect_uri' => 'nullable|url',
            'frontend_url' => 'nullable|url', // URL do frontend para redirect após callback
        ]);

        // Frontend URL (prioridade: request > account > config)
        $frontendUrl = $request->input('frontend_url')
            ?? $this->account->frontend_url
            ?? config('app.frontend_url')
            ?? null;

        // State token inclui frontend_url para redirect após callback
        $state = $this->generateStateToken($this->account->id, $frontendUrl);
        
        // Para callback no backend, redirect_uri é sempre a URL do backend
        // IMPORTANTE: Esta URL será configurada no Meta UMA VEZ para todos os clientes
        // Normaliza a URL para garantir correspondência exata com o Meta
        // Sempre usa o callback do backend para garantir correspondência exata com Meta
        // Ignora redirect_uri vindo do frontend para evitar inconsistências
        $backendCallbackUrl = config('app.url') . '/api/webhooks/instagram/callback';
        $redirectUri = $this->normalizeRedirectUri($backendCallbackUrl);

        // Usa InstagramOAuthClient (similar ao OAuth2::Client do Chatwoot)
        // O client_id é adicionado automaticamente
        $oauthClient = InstagramOAuthClient::fromAppConfig();
        $authUrl = $oauthClient->getAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', $this->getRequiredScopes()),
            'state' => $state,
        ]);

        // Debug: log da URL gerada (sem expor tokens sensíveis)
        Log::info('[INSTAGRAM] Authorization URL generated', [
            'account_id' => $this->account->id,
            'app_id' => substr($oauthClient->getClientId(), 0, 4) . '...',
            'redirect_uri' => $redirectUri,
            'redirect_uri_from_request' => $request->input('redirect_uri'),
            'redirect_uri_normalized' => $redirectUri,
            'app_url' => config('app.url'),
            'frontend_url' => $frontendUrl,
            'url_length' => strlen($authUrl),
            'callback_type' => 'backend', // Callback será processado no backend
            'authorization_url_preview' => substr($authUrl, 0, 200) . '...', // Primeiros 200 caracteres para debug
        ]);
        
        // DEBUG CRÍTICO: Log detalhado para identificar problema de redirect_uri
        Log::warning('[INSTAGRAM] ⚠️ DEBUG: Verifique se redirect_uri está configurado EXATAMENTE no Meta', [
            'redirect_uri_exata' => $redirectUri,
            'redirect_uri_hash' => md5($redirectUri), // Para comparar depois
            'redirect_uri_length' => strlen($redirectUri),
            'redirect_uri_has_trailing_slash' => str_ends_with($redirectUri, '/'),
            'redirect_uri_starts_with_https' => str_starts_with($redirectUri, 'https://'),
            'redirect_uri_has_query_params' => str_contains($redirectUri, '?'),
            'redirect_uri_has_fragment' => str_contains($redirectUri, '#'),
            'message' => '⚠️ Configure esta URL EXATAMENTE no Meta: Valid OAuth Redirect URIs',
            'action_required' => 'Copie e cole esta URL no Meta: ' . $redirectUri,
        ]);

        return response()->json([
            'authorization_url' => $authUrl,
            'state' => $state,
            // Debug info (apenas em desenvolvimento)
            'debug' => config('app.debug') ? [
                'app_id' => substr($oauthClient->getClientId(), 0, 4) . '...',
                'redirect_uri' => $redirectUri,
                'scopes' => $this->getRequiredScopes(),
            ] : null,
        ]);
    }

    /**
     * Processa callback OAuth
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function callback(Request $request): JsonResponse
    {
        // Verifica se houve erro na autorização
        if ($request->has('error')) {
            return $this->handleAuthorizationError($request);
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        // Valida state token
        $accountId = $this->verifyStateToken($request->input('state'));
        if (!$accountId || $accountId !== $this->account->id) {
            return response()->json(['error' => 'Invalid state token'], 400);
        }

        try {
            $redirectUri = $request->input('redirect_uri') 
                ?? config('services.instagram.redirect_uri');
            
            $webhookUrl = $request->input('webhook_url');

            $service = new OAuthCallbackService(
                $this->account,
                $request->input('code'),
                $redirectUri,
                $webhookUrl
            );

            $channel = $service->perform();

            return response()->json([
                'channel_id' => $channel->id,
                'inbox_id' => $channel->inbox->id,
                'instagram_id' => $channel->instagram_id,
                'username' => $channel->inbox->name,
            ], 201);

        } catch (\Exception $e) {
            Log::error("[INSTAGRAM] Authorization failed: {$e->getMessage()}", [
                'account_id' => $this->account->id,
                'exception' => $e,
            ]);

            $errorMessage = $e->getMessage();
            
            // Melhorar mensagens de erro comuns
            if (str_contains($errorMessage, 'Invalid platform app') || str_contains($errorMessage, 'platform')) {
                $errorMessage = 'App ID inválido ou produto Instagram não configurado. Verifique: 1) Se o App ID está correto, 2) Se o produto Instagram está adicionado à aplicação no Facebook Developers, 3) Se o redirect_uri está configurado corretamente.';
            }

            return response()->json([
                'error' => 'Failed to complete authorization',
                'message' => $errorMessage,
            ], 500);
        }
    }

    /**
     * Constrói URL de autorização OAuth
     * 
     * @deprecated Use InstagramOAuthClient::fromAppConfig()->getAuthorizationUrl() ao invés deste método
     * Mantido para compatibilidade, mas será removido no futuro.
     * 
     * @param string $state State token
     * @param string $redirectUri URI de redirecionamento
     * @return string
     */
    protected function buildAuthorizationUrl(string $state, string $redirectUri): string
    {
        // Usa o novo InstagramOAuthClient (similar ao OAuth2::Client do Chatwoot)
        // O client_id é adicionado automaticamente
        $oauthClient = InstagramOAuthClient::fromAppConfig();
        return $oauthClient->getAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', $this->getRequiredScopes()),
            'state' => $state,
        ]);
    }

    /**
     * Retorna escopos obrigatórios
     * 
     * @return array
     */
    protected function getRequiredScopes(): array
    {
        return [
            'instagram_business_basic',
            'instagram_business_manage_messages',
        ];
    }

    /**
     * Valida e normaliza redirect_uri
     * 
     * IMPORTANTE: A URL deve corresponder EXATAMENTE à configurada no Meta.
     * Remove trailing slash, query params, e normaliza protocolo.
     * 
     * @param string $redirectUri
     * @return string
     * @throws \Exception
     */
    protected function validateAndNormalizeRedirectUri(string $redirectUri): string
    {
        // Remove espaços
        $redirectUri = trim($redirectUri);
        
        // Remove query params e fragmentos se existirem (Meta só aceita URL simples)
        $parsed = parse_url($redirectUri);
        
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            throw new \Exception('Invalid redirect_uri format');
        }
        
        // Reconstrói apenas scheme, host e path (sem query params, sem fragment, sem trailing slash)
        $normalized = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $normalized .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            // Remove trailing slash (Meta não aceita)
            $normalized .= rtrim($parsed['path'], '/');
        }
        
        return $normalized;
    }

    /**
     * Normaliza redirect_uri para garantir correspondência exata com Meta
     * 
     * IMPORTANTE: Meta exige correspondência EXATA, então:
     * - Remove trailing slash
     * - Remove query params
     * - Remove fragmentos
     * - Mantém protocolo original (https)
     * 
     * @param string $redirectUri
     * @return string
     */
    protected function normalizeRedirectUri(string $redirectUri): string
    {
        // Remove espaços
        $redirectUri = trim($redirectUri);
        
        // Usa o método de validação que já normaliza
        return $this->validateAndNormalizeRedirectUri($redirectUri);
    }

    /**
     * Gera state token JWT simples
     * 
     * Inclui frontend_url no token para redirect após callback no backend.
     * Isso permite multi-tenant: uma URL no backend, qualquer frontend funciona.
     * 
     * @param int $accountId
     * @param string|null $frontendUrl URL do frontend para redirect após callback
     * @param int|null $inboxId ID do inbox existente para associar ao channel
     * @param string|null $webhookUrl URL do webhook do frontend (opcional)
     * @return string
     */
    protected function generateStateToken(int $accountId, ?string $frontendUrl = null, ?int $inboxId = null, ?string $webhookUrl = null): string
    {
        $secret = \App\Support\AppConfigHelper::get('instagram', 'app_secret');
        
        if (!$secret) {
            throw new \Exception('Instagram App Secret não configurado');
        }
        
        $payload = [
            'sub' => $accountId,
            'iat' => now()->timestamp,
        ];
        
        // Inclui frontend_url no token para multi-tenant
        // Isso permite que o backend redirecione para qualquer domínio de frontend
        if ($frontendUrl) {
            $payload['frontend_url'] = $frontendUrl;
        }
        
        // Inclui inbox_id no token para associar inbox existente ao channel quando conectar
        if ($inboxId) {
            $payload['inbox_id'] = $inboxId;
        }
        
        // Inclui webhook_url no token para configurar no channel quando criar
        if ($webhookUrl) {
            $payload['webhook_url'] = $webhookUrl;
        }

        // Implementação simples de JWT (header.payload.signature)
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "{$header}.{$payloadEncoded}", $secret, true);
        $signatureEncoded = base64_encode($signature);

        return "{$header}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Verifica e decodifica state token
     * 
     * @param string $token
     * @return int|null Account ID
     */
    protected function verifyStateToken(string $token): ?int
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$header, $payload, $signature] = $parts;
            $secret = \App\Support\AppConfigHelper::get('instagram', 'app_secret');

            // Verifica assinatura
            $expectedSignature = base64_encode(
                hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
            );

            if (!hash_equals($expectedSignature, $signature)) {
                return null;
            }

            // Decodifica payload
            $decoded = json_decode(base64_decode($payload), true);
            
            // Verifica expiração (opcional: 10 minutos)
            $iat = $decoded['iat'] ?? 0;
            if (now()->timestamp - $iat > 600) {
                return null;
            }

            return $decoded['sub'] ?? null;
        } catch (\Exception $e) {
            Log::warning("[INSTAGRAM] Invalid state token: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Trata erro de autorização
     * 
     * @param Request $request
     * @return JsonResponse
     */
    protected function handleAuthorizationError(Request $request): JsonResponse
    {
        $error = $request->input('error');
        $errorDescription = $request->input('error_description', 'Authorization was canceled or failed');

        Log::warning("[INSTAGRAM] Authorization error: {$error}", [
            'error_description' => $errorDescription,
            'account_id' => $this->account->id,
        ]);

        return response()->json([
            'error' => $error,
            'error_description' => $errorDescription,
        ], 400);
    }
}
