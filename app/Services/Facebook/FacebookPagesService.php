<?php

namespace App\Services\Facebook;

use App\Models\Channel\FacebookChannel;
use Illuminate\Support\Facades\Log;

/**
 * Serviço FacebookPagesService
 * 
 * Busca páginas do Facebook do usuário e marca quais já existem.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Facebook
 */
class FacebookPagesService
{
    protected FacebookApiClient $apiClient;
    protected string $userAccessToken;
    protected int $accountId;

    /**
     * Construtor
     * 
     * @param string $userAccessToken Token de acesso do usuário
     * @param int $accountId ID da conta
     */
    public function __construct(string $userAccessToken, int $accountId)
    {
        $this->userAccessToken = $userAccessToken;
        $this->accountId = $accountId;
        $this->apiClient = new FacebookApiClient($userAccessToken);
    }

    /**
     * Busca páginas e marca quais já existem
     * 
     * @return array Páginas com flag 'exists'
     */
    public function fetchPagesWithExistence(): array
    {
        $pages = $this->fetchAllPages();
        
        return $this->markExistingPages($pages);
    }

    /**
     * Busca todas as páginas (com paginação)
     * 
     * @return array
     */
    protected function fetchAllPages(): array
    {
        $allPages = [];
        $response = $this->apiClient->fetchUserPages($this->userAccessToken);
        
        $pages = $response['data'] ?? [];
        $allPages = array_merge($allPages, $pages);

        // Facebook pode retornar paginação via 'paging'
        while (isset($response['paging']['next'])) {
            $nextUrl = $response['paging']['next'];
            $nextResponse = \Illuminate\Support\Facades\Http::get($nextUrl)->json();
            $pages = $nextResponse['data'] ?? [];
            $allPages = array_merge($allPages, $pages);
            $response = $nextResponse;
        }

        return $allPages;
    }

    /**
     * Marca páginas que já existem no sistema
     * 
     * @param array $pages
     * @return array
     */
    protected function markExistingPages(array $pages): array
    {
        if (empty($pages)) {
            return [];
        }

        $pageIds = array_column($pages, 'id');
        $existingChannels = FacebookChannel::where('account_id', $this->accountId)
            ->whereIn('page_id', $pageIds)
            ->pluck('page_id')
            ->toArray();

        return array_map(function ($page) use ($existingChannels) {
            $page['exists'] = in_array($page['id'], $existingChannels);
            return $page;
        }, $pages);
    }
}

