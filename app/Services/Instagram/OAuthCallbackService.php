<?php

namespace App\Services\Instagram;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

/**
 * Serviço OAuthCallbackService
 * 
 * Orquestra o processo completo de OAuth callback do Instagram.
 * Coordena todos os serviços necessários seguindo SOLID.
 * 
 * @package App\Services\Instagram
 */
class OAuthCallbackService
{
    protected Account $account;
    protected string $code;
    protected string $redirectUri;
    protected ?string $webhookUrl;
    protected ?int $inboxId;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param string $code Código de autorização
     * @param string $redirectUri URI de redirecionamento
     * @param string|null $webhookUrl URL do webhook para o frontend (opcional)
     * @param int|null $inboxId ID do inbox existente para associar ao channel (opcional)
     */
    public function __construct(Account $account, string $code, string $redirectUri, ?string $webhookUrl = null, ?int $inboxId = null)
    {
        $this->account = $account;
        $this->code = $code;
        $this->redirectUri = $redirectUri;
        $this->webhookUrl = $webhookUrl;
        $this->inboxId = $inboxId;
    }

    /**
     * Executa o processo completo de OAuth callback
     * 
     * @return \App\Models\Channel\InstagramChannel
     * @throws \Exception
     */
    public function perform(): \App\Models\Channel\InstagramChannel
    {
        // 1. Troca código por tokens (short-lived → long-lived)
        $tokenData = $this->exchangeTokens();

        // 2. Busca detalhes do usuário
        $userDetails = $this->fetchUserDetails($tokenData['access_token']);

        // 3. Cria ou atualiza canal
        $channel = $this->createOrUpdateChannel($userDetails, $tokenData);

        // 4. Marca como reautorizado (remove flags de erro de autorização)
        // Similar ao Chatwoot: channel_instagram.reauthorized!
        $channel->markAsReauthorized();

        // 5. Inscreve webhooks
        $channel->setupWebhooks();

        return $channel;
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

    /**
     * Busca detalhes do usuário Instagram
     * 
     * @param string $accessToken
     * @return array
     */
    protected function fetchUserDetails(string $accessToken): array
    {
        $apiClient = new InstagramApiClient($accessToken);
        return $apiClient->fetchUserDetails($accessToken);
    }

    /**
     * Cria ou atualiza canal
     * 
     * @param array $userDetails
     * @param array $tokenData
     * @return \App\Models\Channel\InstagramChannel
     */
    protected function createOrUpdateChannel(array $userDetails, array $tokenData): \App\Models\Channel\InstagramChannel
    {
        $service = new ChannelCreationService($this->account, $userDetails, $tokenData, $this->webhookUrl, $this->inboxId);
        return $service->perform();
    }
}

