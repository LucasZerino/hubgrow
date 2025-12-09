<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Facebook\OAuthCallbackService;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller FacebookOAuthCallbackController
 * 
 * Processa callback OAuth do Facebook.
 * Esta rota é PÚBLICA (sem autenticação) porque o Facebook redireciona aqui.
 * A segurança é garantida pelo state token.
 * 
 * IMPORTANTE: Esta rota permite multi-tenant porque:
 * - Uma URL única no backend: /api/webhooks/facebook/callback
 * - Funciona com qualquer domínio de frontend
 * - Apenas UMA URL precisa ser configurada no Meta
 * 
 * @package App\Http\Controllers\Webhooks
 */
class FacebookOAuthCallbackController extends Controller
{
    /**
     * Processa callback OAuth do Facebook
     * 
     * Fluxo:
     * 1. Facebook redireciona para esta rota com code e state
     * 2. Backend valida state token e extrai account_id
     * 3. Backend troca código por token (long-lived)
     * 4. Backend retorna HTML que faz redirect para o frontend com o token
     * 5. Frontend usa o token para buscar páginas e criar canal
     * 
     * @param Request $request
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request)
    {
        Log::info('[FACEBOOK OAUTH CALLBACK] Callback recebido', [
            'has_code' => $request->has('code'),
            'has_state' => $request->has('state'),
            'has_error' => $request->has('error'),
        ]);

        // Verifica se houve erro na autorização
        if ($request->has('error')) {
            return $this->handleError($request);
        }

        // Valida parâmetros obrigatórios
        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            // Extrai account_id e frontend_url do state token
            $stateData = $this->verifyStateToken($request->input('state'));
            
            if (!$stateData || !isset($stateData['account_id'])) {
                Log::warning('[FACEBOOK OAUTH CALLBACK] State token inválido');
                return $this->renderError('Invalid state token');
            }

            $accountId = $stateData['account_id'];
            $frontendUrl = $stateData['frontend_url'] ?? null;
            $inboxId = $stateData['inbox_id'] ?? null;

            // Busca account
            $account = Account::find($accountId);
            if (!$account) {
                Log::warning('[FACEBOOK OAUTH CALLBACK] Account não encontrada', [
                    'account_id' => $accountId,
                ]);
                return $this->renderError('Account not found', $frontendUrl);
            }

            Log::info('[FACEBOOK OAUTH CALLBACK] Processando OAuth', [
                'account_id' => $accountId,
                'frontend_url' => $frontendUrl,
                'inbox_id' => $inboxId,
            ]);

            // Busca frontend_url (prioridade: state token > account.frontend_url > config)
            $finalFrontendUrl = $frontendUrl 
                ?? $account->frontend_url 
                ?? config('app.frontend_url')
                ?? 'http://localhost:3000';

            // Processa OAuth (callback é a URL atual do backend, SEM query parameters)
            // IMPORTANTE: redirect_uri deve ser EXATAMENTE o mesmo usado na requisição OAuth
            $baseCallbackUrl = config('app.url') . '/api/webhooks/facebook/callback';
            
            // Usa a mesma normalização que foi usada na geração OAuth
            $callbackUrl = $this->normalizeRedirectUri($baseCallbackUrl);

            Log::info('[FACEBOOK OAUTH CALLBACK] Usando redirect_uri para troca de código', [
                'callback_url' => $callbackUrl,
                'base_callback_url' => $baseCallbackUrl,
                'full_url' => $request->fullUrl(),
                'url_without_query' => $request->url(),
                'app_url' => config('app.url'),
                'message' => '⚠️ redirect_uri deve ser EXATAMENTE igual ao usado na requisição OAuth',
            ]);

            $service = new OAuthCallbackService(
                $account,
                $request->input('code'),
                $callbackUrl, // URL do backend como redirect_uri (sem query params)
                $inboxId // Passa inbox_id para atualizar canal automaticamente
            );

            $result = $service->perform();

            // Se inbox_id foi fornecido e canal foi atualizado, retorna sucesso direto
            if (isset($result['channel_updated']) && $result['channel_updated']) {
                Log::info('[FACEBOOK OAUTH CALLBACK] Canal atualizado automaticamente', [
                    'account_id' => $accountId,
                    'inbox_id' => $inboxId,
                    'channel_id' => $result['channel_id'] ?? null,
                ]);

                // Retorna HTML que faz redirect para o frontend com sucesso
                return $this->renderSuccess($finalFrontendUrl, [
                    'success' => true,
                    'channel_id' => $result['channel_id'] ?? null,
                    'inbox_id' => $result['inbox_id'] ?? $inboxId,
                    'state' => $request->input('state'),
                ]);
            }

            // Se não atualizou automaticamente, retorna token para seleção de páginas
            $tokenData = $result;

            Log::info('[FACEBOOK OAUTH CALLBACK] OAuth processado com sucesso', [
                'account_id' => $accountId,
                'frontend_url' => $finalFrontendUrl,
                'has_access_token' => !empty($tokenData['access_token']),
            ]);

            // Retorna HTML que faz redirect para o frontend com o token
            return $this->renderSuccess($finalFrontendUrl, [
                'access_token' => $tokenData['access_token'],
                'expires_in' => $tokenData['expires_in'] ?? null,
                'token_type' => $tokenData['token_type'] ?? 'bearer',
                'state' => $request->input('state'), // Passa state de volta para o frontend
            ]);

        } catch (\Exception $e) {
            Log::error('[FACEBOOK OAUTH CALLBACK] Erro ao processar callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Tenta extrair frontend_url do state token para redirect de erro
            $frontendUrl = null;
            if ($request->has('state')) {
                try {
                    $stateData = $this->verifyStateToken($request->input('state'));
                    $frontendUrl = $stateData['frontend_url'] ?? null;
                } catch (\Exception $e2) {
                    // Ignora erro ao extrair frontend_url
                }
            }

            $finalFrontendUrl = $frontendUrl 
                ?? config('app.frontend_url')
                ?? 'http://localhost:3000';

            return $this->renderError($e->getMessage(), $finalFrontendUrl);
        }
    }

    /**
     * Retorna HTML que faz redirect para o frontend (sucesso)
     * 
     * @param string $frontendUrl URL do frontend
     * @param array $data Dados para passar para o frontend
     * @return \Illuminate\View\View
     */
    protected function renderSuccess(string $frontendUrl, array $data)
    {
        // Remove barra final se houver
        $frontendUrl = rtrim($frontendUrl, '/');
        
        // Constrói URL de redirect para o frontend
        $redirectUrl = $frontendUrl . '/facebook-callback?' . http_build_query($data);

        Log::info('[FACEBOOK OAUTH CALLBACK] Preparando redirect de sucesso', [
            'redirect_url' => $redirectUrl,
            'frontend_url' => $frontendUrl,
        ]);

        return view('oauth.facebook.success', [
            'redirect_url' => $redirectUrl,
            'data' => $data,
            'frontend_url' => $frontendUrl,
        ]);
    }

    /**
     * Retorna HTML que faz redirect para o frontend (erro)
     * 
     * @param string $message Mensagem de erro
     * @param string|null $frontendUrl URL do frontend (opcional)
     * @return \Illuminate\View\View
     */
    protected function renderError(string $message, ?string $frontendUrl = null)
    {
        $finalFrontendUrl = $frontendUrl ?? config('app.frontend_url') ?? 'http://localhost:3000';
        $finalFrontendUrl = rtrim($finalFrontendUrl, '/');

        // Constrói URL de redirect para o frontend com erro
        $redirectUrl = $finalFrontendUrl . '/facebook-callback?' . http_build_query([
            'error' => 'oauth_failed',
            'error_description' => $message,
        ]);

        Log::info('[FACEBOOK OAUTH CALLBACK] Preparando redirect de erro', [
            'redirect_url' => $redirectUrl,
            'error' => $message,
        ]);

        return view('oauth.facebook.error', [
            'redirect_url' => $redirectUrl,
            'message' => $message,
            'frontend_url' => $finalFrontendUrl,
        ]);
    }

    /**
     * Trata erro de autorização (quando usuário cancela ou Facebook retorna erro)
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    protected function handleError(Request $request)
    {
        $error = $request->input('error');
        $errorDescription = $request->input('error_description', 'Authorization was canceled or failed');

        Log::warning('[FACEBOOK OAUTH CALLBACK] Erro de autorização', [
            'error' => $error,
            'error_description' => $errorDescription,
        ]);

        // Tenta extrair frontend_url do state token
        $frontendUrl = null;
        if ($request->has('state')) {
            try {
                $stateData = $this->verifyStateToken($request->input('state'));
                $frontendUrl = $stateData['frontend_url'] ?? null;
            } catch (\Exception $e) {
                // Ignora erro ao extrair frontend_url
            }
        }

        $finalFrontendUrl = $frontendUrl 
            ?? config('app.frontend_url')
            ?? 'http://localhost:3000';

        return $this->renderError($errorDescription, $finalFrontendUrl);
    }

    /**
     * Verifica e decodifica state token
     * 
     * Retorna array com:
     * - account_id: ID da account
     * - frontend_url: URL do frontend (opcional)
     * 
     * @param string $token State token
     * @return array|null Array com account_id e frontend_url (opcional) ou null se inválido
     */
    protected function verifyStateToken(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 2) {
                return null;
            }

            [$random, $payload] = $parts;
            $decoded = json_decode(base64_decode($payload), true);
            
            if (!$decoded || !isset($decoded['account_id'])) {
                return null;
            }

            // Verifica expiração (15 minutos)
            $exp = $decoded['exp'] ?? 0;
            if (time() > $exp) {
                return null;
            }

            return [
                'account_id' => $decoded['account_id'],
                'frontend_url' => $decoded['frontend_url'] ?? null,
                'inbox_id' => $decoded['inbox_id'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Erro ao verificar state token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Normaliza redirect_uri para garantir correspondência exata
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
}

