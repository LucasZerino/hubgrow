<?php

namespace App\Http\Controllers\Api\V1\Accounts\Instagram;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Instagram\InstagramOAuthClient;
use Illuminate\Support\Facades\Auth;

/**
 * Controller OAuthButtonController
 * 
 * Renderiza uma página HTML no backend com botão OAuth do Instagram.
 * Isso permite que o botão seja servido pelo backend, mantendo toda a lógica OAuth no backend.
 * 
 * O frontend pode abrir esta página em um iframe ou popup.
 * Esta página redireciona automaticamente para a URL OAuth do Instagram.
 * 
 * IMPORTANTE: Esta é uma alternativa opcional. O método atual (botão no frontend) também funciona.
 * 
 * @package App\Http\Controllers\Api\V1\Accounts\Instagram
 */
class OAuthButtonController extends Controller
{
    /**
     * Renderiza página HTML com botão OAuth
     * 
     * Esta página:
     * 1. Gera a URL OAuth
     * 2. Exibe um botão que redireciona para a URL OAuth
     * 3. Ou redireciona automaticamente (se auto_redirect=true)
     * 
     * @param Request $request
     * @param int $accountId
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
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
                    // Valida se é do tipo Instagram
                    $expectedType = \App\Models\Channel\InstagramChannel::class;
                    $actualType = $inbox->channel_type;
                    $isInstagram = $actualType === $expectedType 
                        || trim($actualType) === trim($expectedType);
                    
                    if ($isInstagram && $inbox->channel) {
                        $webhookUrl = $inbox->channel->webhook_url;
                        Log::info('[INSTAGRAM OAuth Button] Webhook URL obtido do channel existente', [
                            'inbox_id' => $inboxId,
                            'webhook_url' => $webhookUrl,
                            'channel_type' => $actualType,
                        ]);
                    } else {
                        Log::warning('[INSTAGRAM OAuth Button] Inbox encontrado mas não é do tipo Instagram', [
                            'inbox_id' => $inboxId,
                            'channel_type' => $actualType,
                            'expected_type' => $expectedType,
                        ]);
                    }
                } else {
                    Log::warning('[INSTAGRAM OAuth Button] Inbox não encontrado para buscar webhook_url', [
                        'inbox_id' => $inboxId,
                        'account_id' => $accountId,
                    ]);
                }
            }
            
            // Log dos parâmetros recebidos
            Log::info('[INSTAGRAM OAuth Button] Parâmetros recebidos', [
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
            $state = $method->invoke($authorizationController, $accountId, $frontendUrl, $inboxId, $webhookUrl);
            
            // Constroi URL do callback (sempre no backend)
            // IMPORTANTE: Usa a URL do backend (ngrok, etc) não do frontend
            $backendCallbackUrl = config('app.url') . '/api/webhooks/instagram/callback';
            $redirectUri = $backendCallbackUrl;
            
            // Normaliza redirect_uri para garantir correspondência exata com Meta
            $normalizeMethod = $reflection->getMethod('normalizeRedirectUri');
            $normalizeMethod->setAccessible(true);
            $redirectUri = $normalizeMethod->invoke($authorizationController, $redirectUri);
            
            Log::info('[INSTAGRAM OAuth Button] URL OAuth gerada', [
                'account_id' => $accountId,
                'redirect_uri' => $redirectUri,
                'app_url' => config('app.url'),
                'frontend_url' => $frontendUrl,
            ]);
            
            // Gera URL OAuth
            $oauthClient = InstagramOAuthClient::fromAppConfig();
            $authUrl = $oauthClient->getAuthorizationUrl([
                'redirect_uri' => $redirectUri,
                'scope' => implode(',', [
                    'instagram_business_basic',
                    'instagram_business_manage_messages',
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
            return view('instagram.oauth.button', [
                'auth_url' => $authUrl,
                'account_id' => $accountId,
                'frontend_url' => $frontendUrl,
            ]);
            
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM OAuth Button] Erro ao gerar URL OAuth', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            
            return view('instagram.oauth.error', [
                'error' => $e->getMessage(),
                'account_id' => $accountId,
            ]);
        }
    }
}

