<?php

namespace App\Http\Controllers\Api\V1\Accounts\Facebook;

use App\Http\Controllers\Api\V1\Accounts\BaseController;
use App\Services\Facebook\ChannelCreationService;
use App\Services\Facebook\FacebookPagesService;
use App\Services\Facebook\FacebookOAuthClient;
use App\Services\Facebook\LongLivedTokenService;
use App\Services\Facebook\ReauthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller AuthorizationsController
 * 
 * Gerencia autorização OAuth do Facebook.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\Facebook
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
        // Verifica se Facebook está configurado
        $isConfigured = \App\Support\AppConfigHelper::isConfigured('facebook');
        
        if (!$isConfigured) {
            return response()->json([
                'error' => 'Facebook não configurado',
                'message' => 'O SuperAdmin precisa configurar as credenciais do Facebook antes de criar canais.',
            ], 503);
        }

        // Verifica limite de canais Facebook
        $account = $this->account;
        if (!$account->canCreateResource('facebook_channels')) {
            $usage = $account->getResourceUsage('facebook_channels');
            return response()->json([
                'error' => 'Limite de canais Facebook excedido',
                'message' => 'Você atingiu o limite de canais Facebook para esta conta.',
                'usage' => $usage,
            ], 402);
        }

        $request->validate([
            'redirect_uri' => 'required|url',
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
        $redirectUriFromRequest = $request->input('redirect_uri');
        if ($redirectUriFromRequest) {
            // Se o frontend passou uma URL, usa ela (mas normaliza)
            $redirectUri = $this->normalizeRedirectUri($redirectUriFromRequest);
        } else {
            // Fallback: constrói URL do backend
            $backendCallbackUrl = config('app.url') . '/api/webhooks/facebook/callback';
            $redirectUri = $this->normalizeRedirectUri($backendCallbackUrl);
        }

        // Usa FacebookOAuthClient (similar ao InstagramOAuthClient)
        // O client_id é adicionado automaticamente
        $oauthClient = FacebookOAuthClient::fromAppConfig();
        $authUrl = $oauthClient->getAuthorizationUrl([
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', $this->getRequiredScopes()),
            'state' => $state,
        ]);

        // Debug: log da URL gerada (sem expor tokens sensíveis)
        Log::info('[FACEBOOK] Authorization URL generated', [
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
        Log::warning('[FACEBOOK] ⚠️ DEBUG: Verifique se redirect_uri está configurado EXATAMENTE no Meta', [
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
     * Retorna os scopes necessários para Facebook Messenger
     * 
     * @return array
     */
    protected function getRequiredScopes(): array
    {
        return [
            'pages_manage_metadata',
            'business_management',
            'pages_messaging',
            'pages_show_list',
            'pages_read_engagement',
        ];
    }

    /**
     * Gera token de state para OAuth
     * 
     * @param int $accountId
     * @param string|null $frontendUrl
     * @param int|null $inboxId ID do inbox existente para atualizar canal (opcional)
     * @return string
     */
    protected function generateStateToken(int $accountId, ?string $frontendUrl = null, ?int $inboxId = null): string
    {
        $payload = [
            'account_id' => $accountId,
            'frontend_url' => $frontendUrl,
            'inbox_id' => $inboxId,
            'exp' => time() + 900, // 15 minutos
        ];
        
        return \Illuminate\Support\Str::random(32) . '.' . base64_encode(json_encode($payload));
    }

    /**
     * Normaliza redirect_uri para garantir correspondência exata com Meta
     * 
     * IMPORTANTE: A URL deve corresponder EXATAMENTE à configurada no Meta.
     * Remove trailing slash, query params, e normaliza protocolo.
     * 
     * @param string $redirectUri
     * @return string
     */
    protected function normalizeRedirectUri(string $redirectUri): string
    {
        // Remove espaços
        $redirectUri = trim($redirectUri);
        
        // Remove query params e fragmentos se existirem (Meta só aceita URL simples)
        $parsed = parse_url($redirectUri);
        
        if (!$parsed) {
            throw new \Exception("Invalid redirect_uri: {$redirectUri}");
        }
        
        // Reconstrói URL sem query params e fragments
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '/';
        
        // Remove trailing slash da path
        $path = rtrim($path, '/');
        
        // Reconstrói URL
        $normalized = "{$scheme}://{$host}{$port}{$path}";
        
        return $normalized;
    }
    /**
     * Lista páginas do Facebook do usuário
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function pages(Request $request): JsonResponse
    {
        // Aceita tanto GET (query params) quanto POST (body)
        $userAccessToken = $request->input('user_access_token') 
            ?? $request->query('user_access_token');
        
        $request->merge(['user_access_token' => $userAccessToken]);
        
        $request->validate([
            'user_access_token' => 'required|string',
        ]);

        try {
            // Troca short-lived por long-lived token
            $longLivedService = new LongLivedTokenService($userAccessToken);
            $longLivedToken = $longLivedService->perform();

            // Busca páginas
            $pagesService = new FacebookPagesService($longLivedToken['access_token'], $this->account->id);
            $pages = $pagesService->fetchPagesWithExistence();

            return response()->json([
                'pages' => $pages,
            ]);

        } catch (\Exception $e) {
            Log::error("[FACEBOOK] Failed to fetch pages: {$e->getMessage()}", [
                'account_id' => $this->account->id,
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to fetch Facebook pages',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Registra página do Facebook (cria ou atualiza canal)
     * 
     * Se inbox_id for fornecido e o inbox existir com canal temporário,
     * atualiza o canal existente. Caso contrário, cria um novo canal.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function registerPage(Request $request): JsonResponse
    {
        // Verifica limite de canais Facebook (apenas se for criar novo)
        $account = $this->account;
        $inboxId = $request->input('inbox_id');
        
        // Se não há inbox_id, verifica limite antes de criar novo
        if (!$inboxId && !$account->canCreateResource('facebook_channels')) {
            $usage = $account->getResourceUsage('facebook_channels');
            return response()->json([
                'error' => 'Limite de canais Facebook excedido',
                'message' => 'Você atingiu o limite de canais Facebook para esta conta.',
                'usage' => $usage,
            ], 402);
        }

        $request->validate([
            'page_id' => 'required|string',
            'page_access_token' => 'required|string',
            'user_access_token' => 'required|string',
            'inbox_name' => 'required|string',
            'inbox_id' => 'nullable|integer|exists:inboxes,id',
        ]);

        try {
            // Troca short-lived por long-lived token
            $longLivedService = new LongLivedTokenService($request->input('user_access_token'));
            $longLivedToken = $longLivedService->perform();

            // Se inbox_id foi fornecido, tenta atualizar o canal existente
            if ($inboxId) {
                Log::info('[FACEBOOK] registerPage - inbox_id fornecido', [
                    'account_id' => $account->id,
                    'inbox_id' => $inboxId,
                ]);
                
                $inbox = $account->inboxes()->find($inboxId);
                
                if (!$inbox) {
                    Log::warning('[FACEBOOK] registerPage - inbox não encontrado', [
                        'account_id' => $account->id,
                        'inbox_id' => $inboxId,
                    ]);
                } elseif (!$inbox->channel instanceof \App\Models\Channel\FacebookChannel) {
                    Log::warning('[FACEBOOK] registerPage - inbox não tem canal Facebook', [
                        'account_id' => $account->id,
                        'inbox_id' => $inboxId,
                        'channel_type' => $inbox->channel_type,
                        'has_channel' => $inbox->channel !== null,
                    ]);
                } else {
                    $channel = $inbox->channel;
                    
                    // Verifica se é um canal temporário (tem temp_token ou page_id começa com temp_)
                    $isTemporary = $channel->page_access_token === 'temp_token' 
                        || str_starts_with($channel->page_id, 'temp_');
                    
                    Log::info('[FACEBOOK] registerPage - verificando canal', [
                        'account_id' => $account->id,
                        'inbox_id' => $inboxId,
                        'channel_id' => $channel->id,
                        'page_id' => $channel->page_id,
                        'page_access_token_preview' => substr($channel->page_access_token, 0, 10) . '...',
                        'is_temporary' => $isTemporary,
                    ]);
                    
                    if ($isTemporary) {
                        Log::info('[FACEBOOK] Atualizando canal temporário existente', [
                            'account_id' => $account->id,
                            'inbox_id' => $inboxId,
                            'channel_id' => $channel->id,
                            'old_page_id' => $channel->page_id,
                            'new_page_id' => $request->input('page_id'),
                            'old_token_preview' => substr($channel->page_access_token, 0, 10) . '...',
                            'new_token_preview' => substr($request->input('page_access_token'), 0, 10) . '...',
                        ]);
                        
                        // Atualiza o canal existente
                        $channel->update([
                            'page_id' => $request->input('page_id'),
                            'page_access_token' => $request->input('page_access_token'),
                            'user_access_token' => $longLivedToken['access_token'],
                        ]);
                        
                        Log::info('[FACEBOOK] Canal atualizado com sucesso', [
                            'channel_id' => $channel->id,
                            'page_id' => $channel->fresh()->page_id,
                        ]);
                        
                        // Atualiza Instagram ID se necessário
                        $this->setInstagramId($channel);
                        
                        // Configura webhooks do Facebook
                        try {
                            $channel->setupWebhooks();
                            Log::info('[FACEBOOK] Webhooks configurados', [
                                'channel_id' => $channel->id,
                                'page_id' => $channel->page_id,
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('[FACEBOOK] Falha ao configurar webhooks', [
                                'channel_id' => $channel->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                        
                        // Marca como reautorizado (limpa flags de erro)
                        $channel->markAsReauthorized();
                        
                        // Atualiza nome do inbox se necessário
                        if ($inbox->name !== $request->input('inbox_name')) {
                            $inbox->update(['name' => $request->input('inbox_name')]);
                        }
                        
                        // Ativa o inbox se estava inativo
                        if (!$inbox->is_active) {
                            $inbox->update(['is_active' => true]);
                            Log::info('[FACEBOOK] Inbox ativado', [
                                'inbox_id' => $inbox->id,
                            ]);
                        }
                        
                        return response()->json([
                            'channel_id' => $channel->id,
                            'inbox_id' => $inbox->id,
                            'page_id' => $channel->page_id,
                            'instagram_id' => $channel->instagram_id,
                            'updated' => true,
                        ], 200);
                    } else {
                        Log::warning('[FACEBOOK] registerPage - canal não é temporário, criando novo', [
                            'account_id' => $account->id,
                            'inbox_id' => $inboxId,
                            'channel_id' => $channel->id,
                            'page_id' => $channel->page_id,
                        ]);
                    }
                }
            } else {
                Log::info('[FACEBOOK] registerPage - inbox_id não fornecido, criando novo canal');
            }

            // Se não há inbox_id ou o inbox não existe/tem canal não-temporário, cria novo
            if (!$account->canCreateResource('facebook_channels')) {
                $usage = $account->getResourceUsage('facebook_channels');
                return response()->json([
                    'error' => 'Limite de canais Facebook excedido',
                    'message' => 'Você atingiu o limite de canais Facebook para esta conta.',
                    'usage' => $usage,
                ], 402);
            }

            $service = new ChannelCreationService(
                $this->account,
                $request->input('page_id'),
                $request->input('page_access_token'),
                $longLivedToken['access_token'],
                $request->input('inbox_name')
            );

            $channel = $service->perform();
                
                // Configura webhooks do Facebook
                try {
                    $channel->setupWebhooks();
                    Log::info('[FACEBOOK] Webhooks configurados', [
                        'channel_id' => $channel->id,
                        'page_id' => $channel->page_id,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('[FACEBOOK] Falha ao configurar webhooks', [
                        'channel_id' => $channel->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                // Marca como reautorizado (limpa flags de erro)
                $channel->markAsReauthorized();

            return response()->json([
                'channel_id' => $channel->id,
                'inbox_id' => $channel->inbox->id,
                'page_id' => $channel->page_id,
                'instagram_id' => $channel->instagram_id,
                    'updated' => false,
            ], 201);

        } catch (\Exception $e) {
            Log::error("[FACEBOOK] Failed to register page: {$e->getMessage()}", [
                'account_id' => $this->account->id,
                'inbox_id' => $inboxId,
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to register Facebook page',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Define Instagram ID para o canal
     * 
     * @param \App\Models\Channel\FacebookChannel $channel
     * @return void
     */
    protected function setInstagramId(\App\Models\Channel\FacebookChannel $channel): void
    {
        try {
            $apiClient = new \App\Services\Facebook\FacebookApiClient($channel->page_access_token);
            $instagramId = $apiClient->fetchInstagramBusinessAccount($channel->page_id, $channel->page_access_token);
            
            if ($instagramId) {
                $channel->update(['instagram_id' => $instagramId]);
                Log::info('[FACEBOOK] Instagram ID definido', [
                    'channel_id' => $channel->id,
                    'instagram_id' => $instagramId,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("[FACEBOOK] Failed to set Instagram ID: {$e->getMessage()}", [
                'channel_id' => $channel->id,
            ]);
        }
    }

    /**
     * Reautoriza página existente
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function reauthorizePage(Request $request): JsonResponse
    {
        $request->validate([
            'inbox_id' => 'required|integer',
            'user_access_token' => 'required|string',
        ]);

        try {
            $inbox = $this->account->inboxes()->findOrFail($request->input('inbox_id'));

            if (!$inbox->channel instanceof \App\Models\Channel\FacebookChannel) {
                return response()->json(['error' => 'Inbox is not a Facebook channel'], 400);
            }

            // Troca short-lived por long-lived token
            $longLivedService = new LongLivedTokenService($request->input('user_access_token'));
            $longLivedToken = $longLivedService->perform();

            $service = new ReauthorizationService($inbox->channel, $longLivedToken['access_token']);
            $channel = $service->perform();

            return response()->json([
                'channel_id' => $channel->id,
                'message' => 'Reauthorization successful',
            ]);

        } catch (\Exception $e) {
            Log::error("[FACEBOOK] Failed to reauthorize page: {$e->getMessage()}", [
                'account_id' => $this->account->id,
                'exception' => $e,
            ]);

            return response()->json([
                'error' => 'Failed to reauthorize Facebook page',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
