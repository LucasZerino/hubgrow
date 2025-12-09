<?php

namespace App\Services\Facebook;

use App\Models\Channel\FacebookChannel;
use Illuminate\Support\Facades\Log;

/**
 * Serviço ReauthorizationService
 * 
 * Reautoriza canal Facebook existente atualizando tokens.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Facebook
 */
class ReauthorizationService
{
    protected FacebookChannel $channel;
    protected string $userAccessToken;
    protected FacebookApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param FacebookChannel $channel
     * @param string $userAccessToken Novo token de acesso do usuário
     */
    public function __construct(FacebookChannel $channel, string $userAccessToken)
    {
        $this->channel = $channel;
        $this->userAccessToken = $userAccessToken;
        $this->apiClient = new FacebookApiClient($userAccessToken);
    }

    /**
     * Executa a reautorização
     * 
     * @return FacebookChannel
     * @throws \Exception
     */
    public function perform(): FacebookChannel
    {
        $this->validateToken();

        // Busca páginas do usuário
        $pages = $this->apiClient->fetchUserPages($this->userAccessToken);
        $pagesData = $pages['data'] ?? [];

        // Encontra a página correspondente
        $pageDetail = $this->findPageDetail($pagesData);

        if (!$pageDetail) {
            throw new \Exception('Page not found in user pages');
        }

        // Atualiza canal com novos tokens
        $this->updateChannel($pageDetail['access_token']);

        // Atualiza Instagram ID se necessário
        $this->updateInstagramId();

        return $this->channel->fresh();
    }

    /**
     * Valida token
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateToken(): void
    {
        if (empty($this->userAccessToken)) {
            throw new \Exception('User access token is required');
        }
    }

    /**
     * Encontra detalhes da página correspondente
     * 
     * @param array $pagesData
     * @return array|null
     */
    protected function findPageDetail(array $pagesData): ?array
    {
        foreach ($pagesData as $page) {
            if ($page['id'] === $this->channel->page_id) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Atualiza canal com novos tokens
     * 
     * @param string $pageAccessToken Novo token de acesso da página
     * @return void
     */
    protected function updateChannel(string $pageAccessToken): void
    {
        $this->channel->update([
            'user_access_token' => $this->userAccessToken,
            'page_access_token' => $pageAccessToken,
        ]);
    }

    /**
     * Atualiza Instagram ID se necessário
     * 
     * @return void
     */
    protected function updateInstagramId(): void
    {
        try {
            $apiClient = new FacebookApiClient($this->channel->page_access_token);
            $instagramId = $apiClient->fetchInstagramBusinessAccount(
                $this->channel->page_id,
                $this->channel->page_access_token
            );
            
            if ($instagramId && $instagramId !== $this->channel->instagram_id) {
                $this->channel->update(['instagram_id' => $instagramId]);
            }
        } catch (\Exception $e) {
            Log::warning("[FACEBOOK] Failed to update Instagram ID: {$e->getMessage()}");
        }
    }
}

