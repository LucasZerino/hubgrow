<?php

namespace App\Services\Facebook;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Serviço OAuthCallbackService
 * 
 * Orquestra o processo completo de OAuth callback do Facebook.
 * Se inbox_id for fornecido, automaticamente busca a primeira página e atualiza o canal.
 * Caso contrário, retorna o token para seleção de páginas no frontend.
 * 
 * @package App\Services\Facebook
 */
class OAuthCallbackService
{
    protected Account $account;
    protected string $code;
    protected string $redirectUri;
    protected ?int $inboxId;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param string $code Código de autorização
     * @param string $redirectUri URI de redirecionamento
     * @param int|null $inboxId ID do inbox existente para atualizar canal automaticamente (opcional)
     */
    public function __construct(Account $account, string $code, string $redirectUri, ?int $inboxId = null)
    {
        $this->account = $account;
        $this->code = $code;
        $this->redirectUri = $redirectUri;
        $this->inboxId = $inboxId;
    }

    /**
     * Executa o processo completo de OAuth callback
     * 
     * Se inbox_id for fornecido:
     * 1. Troca código por tokens
     * 2. Busca a primeira página disponível
     * 3. Atualiza o canal existente automaticamente
     * 
     * Caso contrário:
     * Retorna o token de acesso para seleção de páginas no frontend
     * 
     * @return array
     *   - Se atualizado: ['channel_updated' => true, 'channel_id' => int, 'inbox_id' => int]
     *   - Se não atualizado: ['access_token' => string, 'expires_in' => int, 'token_type' => string]
     * @throws \Exception
     */
    public function perform(): array
    {
        // Troca código por tokens (short-lived → long-lived)
        $tokenData = $this->exchangeTokens();

        Log::info('[FACEBOOK OAUTH CALLBACK] Token obtido com sucesso', [
            'account_id' => $this->account->id,
            'inbox_id' => $this->inboxId,
            'has_access_token' => !empty($tokenData['access_token']),
            'expires_in' => $tokenData['expires_in'] ?? null,
        ]);

        // Se inbox_id foi fornecido, tenta atualizar o canal automaticamente
        if ($this->inboxId) {
            try {
                return $this->updateChannelAutomatically($tokenData['access_token']);
            } catch (\Exception $e) {
                Log::warning('[FACEBOOK OAUTH CALLBACK] Falha ao atualizar canal automaticamente, retornando token para seleção', [
                    'account_id' => $this->account->id,
                    'inbox_id' => $this->inboxId,
                    'error' => $e->getMessage(),
                ]);
                // Se falhar, retorna token para seleção manual
                return $tokenData;
            }
        }

        // Se não tem inbox_id, retorna token para seleção de páginas
        return $tokenData;
    }

    /**
     * Atualiza canal automaticamente usando a primeira página disponível
     * 
     * @param string $userAccessToken Token de acesso do usuário
     * @return array
     * @throws \Exception
     */
    protected function updateChannelAutomatically(string $userAccessToken): array
    {
        Log::info('[FACEBOOK OAUTH CALLBACK] Tentando atualizar canal automaticamente', [
            'account_id' => $this->account->id,
            'inbox_id' => $this->inboxId,
        ]);

        // Busca inbox existente
        $inbox = $this->account->inboxes()->find($this->inboxId);
        if (!$inbox) {
            throw new \Exception('Inbox não encontrado');
        }

        // Verifica se é do tipo Facebook
        $expectedType = \App\Models\Channel\FacebookChannel::class;
        $actualType = $inbox->channel_type;
        $isFacebook = $actualType === $expectedType || trim($actualType) === trim($expectedType);
        
        if (!$isFacebook) {
            throw new \Exception('Inbox não é do tipo Facebook');
        }

        // Busca channel existente
        $channel = $inbox->channel;
        if (!$channel || !($channel instanceof \App\Models\Channel\FacebookChannel)) {
            throw new \Exception('Channel não encontrado ou não é do tipo Facebook');
        }

        // Verifica se é temporário
        $isTemporary = $channel->page_access_token === 'temp_token' 
            || str_starts_with($channel->page_id, 'temp_');
        
        if (!$isTemporary) {
            Log::info('[FACEBOOK OAUTH CALLBACK] Canal não é temporário, apenas atualizando tokens', [
                'channel_id' => $channel->id,
            ]);
            // Apenas atualiza tokens, não precisa buscar páginas
            $longLivedService = new LongLivedTokenService($userAccessToken);
            $longLivedToken = $longLivedService->perform();
            
            $channel->update([
                'user_access_token' => $longLivedToken['access_token'],
            ]);
            
            return [
                'channel_updated' => true,
                'channel_id' => $channel->id,
                'inbox_id' => $inbox->id,
            ];
        }

        // Busca páginas do usuário
        $longLivedService = new LongLivedTokenService($userAccessToken);
        $longLivedToken = $longLivedService->perform();

        $pagesService = new FacebookPagesService($longLivedToken['access_token'], $this->account->id);
        $pages = $pagesService->fetchPagesWithExistence();

        if (empty($pages)) {
            throw new \Exception('Nenhuma página do Facebook encontrada');
        }

        // Usa a primeira página disponível (não conectada)
        $selectedPage = null;
        foreach ($pages as $page) {
            if (!($page['already_connected'] ?? false)) {
                $selectedPage = $page;
                break;
            }
        }

        // Se todas já estão conectadas, usa a primeira mesmo assim
        if (!$selectedPage) {
            $selectedPage = $pages[0];
        }

        Log::info('[FACEBOOK OAUTH CALLBACK] Página selecionada automaticamente', [
            'page_id' => $selectedPage['id'],
            'page_name' => $selectedPage['name'] ?? null,
            'channel_id' => $channel->id,
        ]);

        // Atualiza o canal com os dados da página
        $channel->update([
            'page_id' => $selectedPage['id'],
            'page_access_token' => $selectedPage['access_token'],
            'user_access_token' => $longLivedToken['access_token'],
        ]);

        // Configura Instagram ID se disponível
        $this->setInstagramId($channel);

        // Atualiza nome do inbox se necessário
        $pageName = $selectedPage['name'] ?? null;
        if ($pageName && $inbox->name !== $pageName) {
            $inbox->update(['name' => $pageName]);
        }

        // Ativa o inbox
        if (!$inbox->is_active) {
            $inbox->update(['is_active' => true]);
        }

        // Configura webhooks do Facebook
        try {
            $channel->setupWebhooks();
            Log::info('[FACEBOOK OAUTH CALLBACK] Webhooks configurados', [
                'channel_id' => $channel->id,
                'page_id' => $channel->page_id,
            ]);
        } catch (\Exception $e) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Falha ao configurar webhooks', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Marca como reautorizado (limpa flags de erro)
        $channel->markAsReauthorized();

        Log::info('[FACEBOOK OAUTH CALLBACK] Canal atualizado automaticamente com sucesso', [
            'channel_id' => $channel->id,
            'inbox_id' => $inbox->id,
            'page_id' => $channel->page_id,
        ]);

        return [
            'channel_updated' => true,
            'channel_id' => $channel->id,
            'inbox_id' => $inbox->id,
            'page_id' => $channel->page_id,
            'instagram_id' => $channel->instagram_id,
        ];
    }

    /**
     * Configura Instagram ID se disponível
     * 
     * @param \App\Models\Channel\FacebookChannel $channel
     * @return void
     */
    protected function setInstagramId(\App\Models\Channel\FacebookChannel $channel): void
    {
        try {
            // Tenta buscar Instagram ID associado à página
            $apiClient = new FacebookApiClient($channel->page_access_token);
            $instagramId = $apiClient->fetchInstagramBusinessAccount($channel->page_id, $channel->page_access_token);
            
            if ($instagramId) {
                $channel->update(['instagram_id' => $instagramId]);
                Log::info('[FACEBOOK OAUTH CALLBACK] Instagram ID configurado', [
                    'channel_id' => $channel->id,
                    'instagram_id' => $instagramId,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('[FACEBOOK OAUTH CALLBACK] Erro ao buscar Instagram ID', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
            // Não falha o processo se não conseguir buscar Instagram ID
        }
    }

    /**
     * Troca código por tokens
     * 
     * @return array
     */
    protected function exchangeTokens(): array
    {
        $service = new TokenExchangeService($this->code, $this->redirectUri);
        return $service->perform();
    }
}

