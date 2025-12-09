<?php

namespace App\Services\WhatsApp;

use App\Models\Channel\WhatsAppChannel;
use Illuminate\Support\Facades\Log;

/**
 * Serviço SyncTemplatesService
 * 
 * Sincroniza templates de mensagem do WhatsApp.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class SyncTemplatesService
{
    protected WhatsAppChannel $channel;
    protected FacebookApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param WhatsAppChannel $channel
     */
    public function __construct(WhatsAppChannel $channel)
    {
        $this->channel = $channel;
        $accessToken = $channel->provider_config['api_key'] ?? '';
        $this->apiClient = new FacebookApiClient($accessToken);
    }

    /**
     * Sincroniza templates do WhatsApp
     * 
     * @return void
     */
    public function perform(): void
    {
        $wabaId = $this->channel->provider_config['business_account_id'] ?? null;
        $accessToken = $this->channel->provider_config['api_key'] ?? null;

        if (!$wabaId || !$accessToken) {
            throw new \Exception('WABA ID or access token missing');
        }

        try {
            $templates = $this->fetchTemplates($wabaId, $accessToken);
            
            $this->channel->update([
                'message_templates' => $templates,
                'message_templates_last_updated' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("[WHATSAPP] Template sync failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Busca templates do WABA
     * 
     * @param string $wabaId
     * @param string $accessToken
     * @return array
     */
    protected function fetchTemplates(string $wabaId, string $accessToken): array
    {
        $apiVersion = config('services.whatsapp.api_version', 'v22.0');
        $url = FacebookApiClient::BASE_URI . '/' . $apiVersion . '/' . $wabaId . '/message_templates';
        
        $response = \Illuminate\Support\Facades\Http::withToken($accessToken)
            ->get($url);

        if (!$response->successful()) {
            throw new \Exception("Failed to fetch templates: {$response->body()}");
        }

        $data = $response->json();
        $templates = $data['data'] ?? [];

        // Busca próxima página se houver
        $nextUrl = $data['paging']['next'] ?? null;
        if ($nextUrl) {
            $nextTemplates = $this->fetchNextPage($nextUrl);
            $templates = array_merge($templates, $nextTemplates);
        }

        return $templates;
    }

    /**
     * Busca próxima página de templates
     * 
     * @param string $url
     * @return array
     */
    protected function fetchNextPage(string $url): array
    {
        $response = \Illuminate\Support\Facades\Http::get($url);

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json();
        $templates = $data['data'] ?? [];

        // Recursivamente busca mais páginas
        $nextUrl = $data['paging']['next'] ?? null;
        if ($nextUrl) {
            $nextTemplates = $this->fetchNextPage($nextUrl);
            $templates = array_merge($templates, $nextTemplates);
        }

        return $templates;
    }
}

