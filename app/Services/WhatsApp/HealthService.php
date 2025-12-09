<?php

namespace App\Services\WhatsApp;

use App\Models\Channel\WhatsAppChannel;
use Illuminate\Support\Facades\Log;

/**
 * Serviço HealthService
 * 
 * Busca informações de saúde/status do número de telefone WhatsApp.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class HealthService
{
    protected WhatsAppChannel $channel;
    protected string $accessToken;
    protected string $apiVersion;
    protected FacebookApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param WhatsAppChannel $channel
     */
    public function __construct(WhatsAppChannel $channel)
    {
        $this->channel = $channel;
        $this->accessToken = $channel->provider_config['api_key'] ?? '';
        $this->apiVersion = config('services.whatsapp.api_version', 'v22.0');
        $this->apiClient = new FacebookApiClient($this->accessToken);
    }

    /**
     * Busca status de saúde do número
     * 
     * @return array
     * @throws \Exception
     */
    public function fetchHealthStatus(): array
    {
        $this->validateChannel();

        $phoneNumberId = $this->channel->provider_config['phone_number_id'];
        $healthData = $this->apiClient->fetchPhoneHealth($phoneNumberId);

        return $this->formatHealthResponse($healthData);
    }

    /**
     * Valida se o canal está configurado corretamente
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateChannel(): void
    {
        if (empty($this->channel)) {
            throw new \Exception('Channel is required');
        }

        if (empty($this->accessToken)) {
            throw new \Exception('API key is missing');
        }

        if (empty($this->channel->provider_config['phone_number_id'])) {
            throw new \Exception('Phone number ID is missing');
        }
    }

    /**
     * Formata resposta da API para formato interno
     * 
     * @param array $response Resposta da API
     * @return array
     */
    protected function formatHealthResponse(array $response): array
    {
        return [
            'display_phone_number' => $response['display_phone_number'] ?? null,
            'verified_name' => $response['verified_name'] ?? null,
            'name_status' => $response['name_status'] ?? null,
            'quality_rating' => $response['quality_rating'] ?? null,
            'messaging_limit_tier' => $response['messaging_limit_tier'] ?? null,
            'account_mode' => $response['account_mode'] ?? null,
            'code_verification_status' => $response['code_verification_status'] ?? null,
            'throughput' => $response['throughput'] ?? null,
            'last_onboarded_time' => $response['last_onboarded_time'] ?? null,
            'platform_type' => $response['platform_type'] ?? null,
            'business_id' => $this->channel->provider_config['business_account_id'] ?? null,
        ];
    }
}

