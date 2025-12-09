<?php

namespace App\Http\Controllers\Api\V1\Accounts\Facebook;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Facebook\FacebookOAuthClient;

/**
 * Controller OAuthButtonController
 * 
 * Renderiza uma página HTML no backend com botão OAuth do Facebook.
 * Isso permite que o botão seja servido pelo backend, mantendo toda a lógica OAuth no backend.
 * 
 * O frontend pode abrir esta página em um iframe ou popup.
 * Esta página redireciona automaticamente para a URL OAuth do Facebook.
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\Facebook
 */
class OAuthButtonController extends Controller
{
    /**
     * Renderiza página HTML com botão OAuth
     * 
     * Esta página pode ser acessada:
     * 1. Diretamente (sem iframe) - redireciona para OAuth
     * 2. Em iframe - mostra botão que comunica com parent via postMessage
     * 
     * @param Request $request
     * @param int $accountId
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function show(Request $request, int $accountId)
    {
        // Busca account
        $account = Account::findOrFail($accountId);
        
        // IMPORTANTE: Se estiver em iframe, não precisa verificar autenticação
        // O iframe é carregado pelo frontend autenticado, então já está seguro
        // Apenas verifica se account existe e está ativa
        if ($account->status !== Account::STATUS_ACTIVE) {
            abort(403, 'Account inactive');
        }
        
        try {
            // Frontend URL para redirect após callback (opcional)
            $frontendUrl = $request->input('frontend_url')
                ?? $account->frontend_url
                ?? config('app.frontend_url')
                ?? null;
            
            // Inbox ID para associar inbox existente ao channel quando conectar (opcional)
            $inboxId = $request->input('inbox_id') ? (int) $request->input('inbox_id') : null;
            
            // Webhook URL - prioridade: request > channel existente > null
            $webhookUrl = $request->input('webhook_url');
            
            // Se não foi fornecido no request e tem inbox_id, busca do channel existente
            if (!$webhookUrl && $inboxId) {
                // Busca inbox sem filtro de channel_type (mais robusto)
                $inbox = \App\Models\Inbox::where('id', $inboxId)
                    ->where('account_id', $accountId)
                    ->with('channel')
                    ->first();
                
                if ($inbox) {
                    // Valida se é do tipo Facebook
                    $expectedType = \App\Models\Channel\FacebookChannel::class;
                    $actualType = $inbox->channel_type;
                    $isFacebook = $actualType === $expectedType 
                        || trim($actualType) === trim($expectedType);
                    
                    if ($isFacebook && $inbox->channel) {
                        $webhookUrl = $inbox->channel->webhook_url ?? null;
                        Log::info('[FACEBOOK OAuth Button] Webhook URL obtido do channel existente', [
                            'inbox_id' => $inboxId,
                            'webhook_url' => $webhookUrl,
                            'channel_type' => $actualType,
                        ]);
                    } else {
                        Log::warning('[FACEBOOK OAuth Button] Inbox encontrado mas não é do tipo Facebook', [
                            'inbox_id' => $inboxId,
                            'channel_type' => $actualType,
                            'expected_type' => $expectedType,
                        ]);
                    }
                } else {
                    Log::warning('[FACEBOOK OAuth Button] Inbox não encontrado para buscar webhook_url', [
                        'inbox_id' => $inboxId,
                        'account_id' => $accountId,
                    ]);
                }
            }
            
            // Log dos parâmetros recebidos
            Log::info('[FACEBOOK OAuth Button] Parâmetros recebidos', [
                'account_id' => $accountId,
                'inbox_id' => $inboxId,
                'inbox_id_from_request' => $request->input('inbox_id'),
                'webhook_url_from_request' => $request->input('webhook_url'),
                'webhook_url_final' => $webhookUrl,
                'frontend_url' => $frontendUrl,
            ]);
            
            // State token inclui frontend_url, inbox_id e webhook_url (se fornecido) para redirect após callback
            $authorizationController = new AuthorizationsController();
            $reflection = new \ReflectionClass($authorizationController);
            $method = $reflection->getMethod('generateStateToken');
            $method->setAccessible(true);
            // Passa inbox_id para o state token (similar ao Instagram)
            $state = $method->invoke($authorizationController, $accountId, $frontendUrl, $inboxId);
            
            // Constroi URL do callback (sempre no backend)
            // IMPORTANTE: Usa a URL do backend (ngrok, etc) não do frontend
            $backendCallbackUrl = config('app.url') . '/api/webhooks/facebook/callback';
            $redirectUri = $backendCallbackUrl;
            
            // Normaliza redirect_uri para garantir correspondência exata com Meta
            $normalizeMethod = $reflection->getMethod('normalizeRedirectUri');
            $normalizeMethod->setAccessible(true);
            $redirectUri = $normalizeMethod->invoke($authorizationController, $redirectUri);
            
            // DEBUG CRÍTICO: Log detalhado para identificar problema de redirect_uri
            Log::warning('[FACEBOOK OAuth Button] ⚠️ DEBUG: Verifique se redirect_uri está configurado EXATAMENTE no Meta', [
                'redirect_uri_exata' => $redirectUri,
                'redirect_uri_hash' => md5($redirectUri), // Para comparar depois
                'redirect_uri_length' => strlen($redirectUri),
                'redirect_uri_has_trailing_slash' => str_ends_with($redirectUri, '/'),
                'redirect_uri_starts_with_https' => str_starts_with($redirectUri, 'https://'),
                'redirect_uri_has_query_params' => str_contains($redirectUri, '?'),
                'redirect_uri_has_fragment' => str_contains($redirectUri, '#'),
                'app_url' => config('app.url'),
                'backend_callback_url' => $backendCallbackUrl,
                'message' => '⚠️ Configure esta URL EXATAMENTE no Meta: Valid OAuth Redirect URIs',
                'action_required' => 'Copie e cole esta URL no Meta: ' . $redirectUri,
            ]);
            
            Log::info('[FACEBOOK OAuth Button] URL OAuth gerada', [
                'account_id' => $accountId,
                'redirect_uri' => $redirectUri,
                'app_url' => config('app.url'),
                'frontend_url' => $frontendUrl,
            ]);
            
            // Gera URL OAuth
            $oauthClient = FacebookOAuthClient::fromAppConfig();
            $authUrl = $oauthClient->getAuthorizationUrl([
                'redirect_uri' => $redirectUri,
                'scope' => implode(',', [
                    'pages_manage_metadata',
                    'business_management',
                    'pages_messaging',
                    'pages_show_list',
                    'pages_read_engagement',
                ]),
                'state' => $state,
            ]);
            
            // Auto-redirect se solicitado
            $autoRedirect = $request->input('auto_redirect', false);
            
            if ($autoRedirect) {
                // Redireciona automaticamente para OAuth
                return redirect($authUrl);
            }
            
            // Renderiza página HTML com botão
            return view('facebook.oauth.button', [
                'auth_url' => $authUrl,
                'account_id' => $accountId,
                'frontend_url' => $frontendUrl,
            ]);
            
        } catch (\Exception $e) {
            Log::error('[FACEBOOK OAuth Button] Erro ao gerar URL OAuth', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            
            $frontendUrl = $request->input('frontend_url')
                ?? $account->frontend_url
                ?? config('app.frontend_url')
                ?? 'http://localhost:5173';
            
            return view('facebook.oauth.error', [
                'error' => $e->getMessage(),
                'account_id' => $accountId,
                'redirect_url' => $frontendUrl,
            ]);
        }
    }
}

