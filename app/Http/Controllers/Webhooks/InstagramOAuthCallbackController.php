<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Instagram\OAuthCallbackService;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller InstagramOAuthCallbackController
 * 
 * Processa callback OAuth do Instagram.
 * Esta rota é PÚBLICA (sem autenticação) porque o Instagram redireciona aqui.
 * A segurança é garantida pelo state token JWT.
 * 
 * IMPORTANTE: Esta rota permite multi-tenant porque:
 * - Uma URL única no backend: /api/webhooks/instagram/callback
 * - Funciona com qualquer domínio de frontend
 * - Apenas UMA URL precisa ser configurada no Meta
 * 
 * @package App\Http\Controllers\Webhooks
 */
class InstagramOAuthCallbackController extends Controller
{
    /**
     * Processa callback OAuth do Instagram
     * 
     * Fluxo:
     * 1. Instagram redireciona para esta rota com code e state
     * 2. Backend valida state token e extrai account_id
     * 3. Backend processa OAuth, cria Channel + Inbox
     * 4. Backend retorna HTML que faz redirect para o frontend
     * 
     * @param Request $request
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request)
    {
        Log::info('[INSTAGRAM OAUTH CALLBACK] Callback recebido', [
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
                Log::warning('[INSTAGRAM OAUTH CALLBACK] State token inválido');
                return $this->renderError('Invalid state token');
            }

            $accountId = $stateData['account_id'];
            $frontendUrl = $stateData['frontend_url'] ?? null;
            $inboxId = isset($stateData['inbox_id']) && $stateData['inbox_id'] > 0 ? (int) $stateData['inbox_id'] : null;
            $webhookUrl = $stateData['webhook_url'] ?? null;

            // Busca account
            $account = Account::find($accountId);
            if (!$account) {
                Log::warning('[INSTAGRAM OAUTH CALLBACK] Account não encontrada', [
                    'account_id' => $accountId,
                ]);
                return $this->renderError('Account not found', $frontendUrl);
            }

            Log::info('[INSTAGRAM OAUTH CALLBACK] Processando OAuth', [
                'account_id' => $accountId,
                'frontend_url' => $frontendUrl,
                'inbox_id' => $inboxId,
                'inbox_id_raw' => $stateData['inbox_id'] ?? null,
                'inbox_id_type' => gettype($stateData['inbox_id'] ?? null),
                'webhook_url' => $webhookUrl,
                'state_data_keys' => array_keys($stateData),
                'state_data_full' => $stateData, // Log completo para debug
            ]);

            // Busca frontend_url (prioridade: state token > account.frontend_url > config)
            $finalFrontendUrl = $frontendUrl 
                ?? $account->frontend_url 
                ?? config('app.frontend_url')
                ?? 'http://localhost:3000';

            // Processa OAuth (callback é a URL atual do backend, SEM query parameters)
            // IMPORTANTE: redirect_uri deve ser EXATAMENTE o mesmo usado na requisição OAuth
            // Deve usar a mesma normalização que foi usada na geração da URL OAuth
            $baseCallbackUrl = config('app.url') . '/api/webhooks/instagram/callback';
            
            // Usa a mesma normalização que foi usada na geração OAuth (AuthorizationsController::normalizeRedirectUri)
            // Remove trailing slash, query params, fragments, etc.
            $callbackUrl = $this->normalizeRedirectUri($baseCallbackUrl);
            // webhook_url vem do state token (não do request)

            Log::info('[INSTAGRAM OAUTH CALLBACK] Usando redirect_uri para troca de código', [
                'callback_url' => $callbackUrl,
                'base_callback_url' => $baseCallbackUrl,
                'full_url' => $request->fullUrl(),
                'url_without_query' => $request->url(),
                'app_url' => config('app.url'),
                'webhook_url' => $webhookUrl,
                'inbox_id' => $inboxId,
                'message' => '⚠️ redirect_uri deve ser EXATAMENTE igual ao usado na requisição OAuth',
            ]);

            $service = new OAuthCallbackService(
                $account,
                $request->input('code'),
                $callbackUrl, // URL do backend como redirect_uri (sem query params)
                $webhookUrl,
                $inboxId // Inbox ID para associar inbox existente ao channel (opcional)
            );

            $channel = $service->perform();

            // IMPORTANTE: Tenta carregar o relacionamento de várias formas
            // 1. Verifica se já está carregado
            if (!$channel->relationLoaded('inbox')) {
                $channel->load('inbox');
            }
            
            // 2. Se ainda não encontrar, busca diretamente no banco
            if (!$channel->inbox) {
                Log::warning('[INSTAGRAM OAUTH CALLBACK] Relacionamento inbox não encontrado, buscando diretamente no banco', [
                    'channel_id' => $channel->id,
                    'account_id' => $accountId,
                    'inbox_id_from_state' => $inboxId,
                ]);
                
                $inbox = \App\Models\Inbox::where('channel_type', \App\Models\Channel\InstagramChannel::class)
                    ->where('channel_id', $channel->id)
                    ->where('account_id', $accountId)
                    ->first();
                
                if ($inbox) {
                    $channel->setRelation('inbox', $inbox);
                    Log::info('[INSTAGRAM OAUTH CALLBACK] Inbox encontrado diretamente no banco', [
                        'channel_id' => $channel->id,
                        'inbox_id' => $inbox->id,
                    ]);
                }
            }
            
            // 3. Se ainda não encontrar e tiver inbox_id no state, busca pelo inbox_id e atualiza se necessário
            if (!$channel->inbox && $inboxId) {
                Log::warning('[INSTAGRAM OAUTH CALLBACK] Buscando inbox pelo inbox_id do state token', [
                    'channel_id' => $channel->id,
                    'inbox_id' => $inboxId,
                ]);
                
                $inboxById = \App\Models\Inbox::where('id', $inboxId)
                    ->where('account_id', $accountId)
                    ->where('channel_type', \App\Models\Channel\InstagramChannel::class)
                    ->first();
                
                if ($inboxById) {
                    // Se o inbox não tem o channel_id atualizado, atualiza agora
                    if ($inboxById->channel_id != $channel->id) {
                        Log::warning('[INSTAGRAM OAUTH CALLBACK] Inbox encontrado mas channel_id não corresponde, atualizando', [
                            'inbox_id' => $inboxById->id,
                            'current_channel_id' => $inboxById->channel_id,
                            'expected_channel_id' => $channel->id,
                        ]);
                        $inboxById->update(['channel_id' => $channel->id]);
                        $inboxById->refresh();
                    }
                    
                    $channel->setRelation('inbox', $inboxById);
                    Log::info('[INSTAGRAM OAUTH CALLBACK] Inbox encontrado pelo inbox_id do state token e relacionamento forçado', [
                        'channel_id' => $channel->id,
                        'inbox_id' => $inboxById->id,
                    ]);
                } else {
                    Log::error('[INSTAGRAM OAUTH CALLBACK] Inbox não encontrado pelo inbox_id do state token', [
                        'inbox_id' => $inboxId,
                        'channel_id' => $channel->id,
                        'account_id' => $accountId,
                    ]);
                }
            }

            // Valida que o inbox foi criado/associado
            if (!$channel->inbox) {
                Log::error('[INSTAGRAM OAUTH CALLBACK] Inbox não foi criado/associado para o canal após todas as tentativas', [
                    'channel_id' => $channel->id,
                    'account_id' => $accountId,
                    'inbox_id_from_state' => $inboxId,
                    'has_inbox_id' => !empty($inboxId),
                ]);
                throw new \Exception('Inbox não foi criado/associado para o canal Instagram');
            }

            // Retorna HTML que faz redirect para o frontend
            // Se tiver inbox_id no state, usa ele (inbox existente); senão, usa o inbox criado
            $finalInboxId = $inboxId ?? $channel->inbox->id;
            $responseData = [
                'channel_id' => $channel->id,
                'inbox_id' => $finalInboxId,
                'instagram_id' => $channel->instagram_id,
                'username' => $channel->inbox->name,
                'state' => $request->input('state'), // Passa state de volta para o frontend
            ];

            // Envia notificação de sucesso via webhook se webhook_url estiver presente
            if ($webhookUrl) {
                try {
                    Log::info('[INSTAGRAM OAUTH CALLBACK] Enviando notificação de sucesso para webhook', [
                        'webhook_url' => $webhookUrl,
                        'data' => $responseData
                    ]);
                    
                    \Illuminate\Support\Facades\Http::post($webhookUrl, [
                        'event' => 'instagram_oauth_success',
                        'data' => $responseData,
                        'timestamp' => now()->toIso8601String(),
                    ]);
                } catch (\Exception $e) {
                    Log::error('[INSTAGRAM OAUTH CALLBACK] Erro ao enviar notificação para webhook', [
                        'webhook_url' => $webhookUrl,
                        'error' => $e->getMessage()
                    ]);
                    // Não interrompe o fluxo principal se o webhook falhar
                }
            }

            Log::info('[INSTAGRAM OAUTH CALLBACK] OAuth processado com sucesso', [
                'account_id' => $accountId,
                'channel_id' => $channel->id,
                'inbox_id' => $channel->inbox->id,
                'frontend_url' => $finalFrontendUrl,
            ]);

            return $this->renderSuccess($finalFrontendUrl, $responseData);

        } catch (\Exception $e) {
            Log::error('[INSTAGRAM OAUTH CALLBACK] Erro ao processar callback', [
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
        $redirectUrl = $frontendUrl . '/instagram-callback?' . http_build_query($data);

        Log::info('[INSTAGRAM OAUTH CALLBACK] Preparando redirect de sucesso', [
            'redirect_url' => $redirectUrl,
            'frontend_url' => $frontendUrl,
        ]);

        return view('oauth.instagram.success', [
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
        $redirectUrl = $finalFrontendUrl . '/instagram-callback?' . http_build_query([
            'error' => 'oauth_failed',
            'error_description' => $message,
        ]);

        Log::info('[INSTAGRAM OAUTH CALLBACK] Preparando redirect de erro', [
            'redirect_url' => $redirectUrl,
            'error' => $message,
        ]);

        return view('oauth.instagram.error', [
            'redirect_url' => $redirectUrl,
            'message' => $message,
            'frontend_url' => $finalFrontendUrl,
        ]);
    }

    /**
     * Trata erro de autorização (quando usuário cancela ou Instagram retorna erro)
     * 
     * @param Request $request
     * @return \Illuminate\View\View
     */
    protected function handleError(Request $request)
    {
        $error = $request->input('error');
        $errorDescription = $request->input('error_description', 'Authorization was canceled or failed');

        Log::warning('[INSTAGRAM OAUTH CALLBACK] Erro de autorização', [
            'error' => $error,
            'error_description' => $errorDescription,
        ]);

        // Tenta extrair frontend_url e webhook_url do state token
        $frontendUrl = null;
        $webhookUrl = null;
        if ($request->has('state')) {
            try {
                $stateData = $this->verifyStateToken($request->input('state'));
                $frontendUrl = $stateData['frontend_url'] ?? null;
                $webhookUrl = $stateData['webhook_url'] ?? null;
            } catch (\Exception $e) {
                // Ignora erro ao extrair dados
            }
        }

        // Envia notificação de erro via webhook se webhook_url estiver presente
        if ($webhookUrl) {
            try {
                Log::info('[INSTAGRAM OAUTH CALLBACK] Enviando notificação de erro para webhook', [
                    'webhook_url' => $webhookUrl,
                    'error' => $error,
                    'error_description' => $errorDescription
                ]);
                
                \Illuminate\Support\Facades\Http::post($webhookUrl, [
                    'event' => 'instagram_oauth_error',
                    'data' => [
                        'error' => $error,
                        'error_description' => $errorDescription,
                        'state' => $request->input('state'),
                    ],
                    'timestamp' => now()->toIso8601String(),
                ]);
            } catch (\Exception $e) {
                Log::error('[INSTAGRAM OAUTH CALLBACK] Erro ao enviar notificação de erro para webhook', [
                    'webhook_url' => $webhookUrl,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $finalFrontendUrl = $frontendUrl 
            ?? config('app.frontend_url')
            ?? 'http://localhost:3000';

        return $this->renderError($errorDescription, $finalFrontendUrl);
    }

    /**
     * Verifica e decodifica state token JWT
     * 
     * Retorna array com:
     * - account_id: ID da account
     * - frontend_url: URL do frontend (opcional)
     * - inbox_id: ID do inbox existente para associar ao channel (opcional)
     * - webhook_url: URL do webhook do frontend (opcional)
     * 
     * @param string $token State token JWT
     * @return array|null Array com account_id, frontend_url, inbox_id e webhook_url (opcional) ou null se inválido
     */
    protected function verifyStateToken(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$header, $payload, $signature] = $parts;
            $secret = \App\Support\AppConfigHelper::get('instagram', 'app_secret');

            if (!$secret) {
                return null;
            }

            // Verifica assinatura
            $expectedSignature = base64_encode(
                hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
            );

            if (!hash_equals($expectedSignature, $signature)) {
                return null;
            }

            // Decodifica payload
            $decoded = json_decode(base64_decode($payload), true);
            
            if (!$decoded || !isset($decoded['sub'])) {
                return null;
            }

            // Verifica expiração (opcional: 10 minutos)
            $iat = $decoded['iat'] ?? 0;
            if (now()->timestamp - $iat > 600) {
                return null;
            }

            return [
                'account_id' => $decoded['sub'],
                'frontend_url' => $decoded['frontend_url'] ?? null,
                'inbox_id' => $decoded['inbox_id'] ?? null,
                'webhook_url' => $decoded['webhook_url'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::warning('[INSTAGRAM OAUTH CALLBACK] Erro ao verificar state token', [
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

