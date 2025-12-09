<?php

namespace App\Services\WhatsApp;

use App\Models\Channel\WhatsAppChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Serviço WebhookSetupService
 * 
 * Configura webhooks do WhatsApp no WABA.
 * Registra número de telefone se necessário.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class WebhookSetupService
{
    protected WhatsAppChannel $channel;
    protected string $wabaId;
    protected string $accessToken;
    protected FacebookApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param WhatsAppChannel $channel
     * @param string $wabaId ID do WhatsApp Business Account
     * @param string $accessToken Token de acesso
     */
    public function __construct(WhatsAppChannel $channel, string $wabaId, string $accessToken)
    {
        $this->channel = $channel;
        $this->wabaId = $wabaId;
        $this->accessToken = $accessToken;
        $this->apiClient = new FacebookApiClient($accessToken);
    }

    /**
     * Executa a configuração do webhook
     * 
     * @return void
     * @throws \Exception
     */
    public function perform(): void
    {
        $this->validateParameters();

        // Registra número se necessário
        if (!$this->isPhoneNumberVerified() || $this->phoneNumberNeedsRegistration()) {
            $this->registerPhoneNumber();
        }

        $this->setupWebhook();
    }

    /**
     * Valida parâmetros obrigatórios
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateParameters(): void
    {
        if (empty($this->channel)) {
            throw new \Exception('Channel is required');
        }

        if (empty($this->wabaId)) {
            throw new \Exception('WABA ID is required');
        }

        if (empty($this->accessToken)) {
            throw new \Exception('Access token is required');
        }
    }

    /**
     * Registra número de telefone com PIN
     * 
     * @return void
     */
    protected function registerPhoneNumber(): void
    {
        $phoneNumberId = $this->channel->provider_config['phone_number_id'];
        $pin = $this->fetchOrCreatePin();

        try {
            $this->apiClient->registerPhoneNumber($phoneNumberId, (string) $pin);
            $this->storePin($pin);
        } catch (\Exception $e) {
            Log::warning("[WHATSAPP] Phone registration failed but continuing: {$e->getMessage()}");
            // Continua com setup do webhook mesmo se registro falhar
        }
    }

    /**
     * Busca ou cria PIN de verificação
     * 
     * @return int PIN de 6 dígitos
     */
    protected function fetchOrCreatePin(): int
    {
        $existingPin = $this->channel->provider_config['verification_pin'] ?? null;
        
        if ($existingPin) {
            return (int) $existingPin;
        }

        // Gera novo PIN de 6 dígitos
        return random_int(100000, 999999);
    }

    /**
     * Armazena PIN na configuração do canal
     * 
     * @param int $pin
     * @return void
     */
    protected function storePin(int $pin): void
    {
        $config = $this->channel->provider_config ?? [];
        $config['verification_pin'] = $pin;
        $this->channel->provider_config = $config;
        $this->channel->save();
    }

    /**
     * Configura webhook no WABA
     * 
     * @return void
     * @throws \Exception
     */
    protected function setupWebhook(): void
    {
        $callbackUrl = $this->buildCallbackUrl();
        $verifyToken = $this->channel->provider_config['webhook_verify_token'] ?? Str::random(32);

        try {
            $this->apiClient->subscribeWabaWebhook($this->wabaId, $callbackUrl, $verifyToken);
            
            // Atualiza verify token se foi gerado
            if (!isset($this->channel->provider_config['webhook_verify_token'])) {
                $config = $this->channel->provider_config ?? [];
                $config['webhook_verify_token'] = $verifyToken;
                $this->channel->provider_config = $config;
                $this->channel->save();
            }
        } catch (\Exception $e) {
            Log::error("[WHATSAPP] Webhook setup failed: {$e->getMessage()}");
            throw new \Exception("Webhook setup failed: {$e->getMessage()}");
        }
    }

    /**
     * Constrói URL do callback do webhook
     * 
     * @return string
     */
    protected function buildCallbackUrl(): string
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $phoneNumber = $this->channel->phone_number;
        
        return "{$frontendUrl}/api/webhooks/whatsapp/{$phoneNumber}";
    }

    /**
     * Verifica se número está verificado
     * 
     * @return bool
     */
    protected function isPhoneNumberVerified(): bool
    {
        try {
            $phoneNumberId = $this->channel->provider_config['phone_number_id'] ?? null;
            
            if (!$phoneNumberId) {
                return false;
            }

            $verified = $this->apiClient->isPhoneNumberVerified($phoneNumberId);
            Log::info("[WHATSAPP] Phone number {$phoneNumberId} verification status: " . ($verified ? 'VERIFIED' : 'NOT VERIFIED'));
            
            return $verified;
        } catch (\Exception $e) {
            Log::error("[WHATSAPP] Phone verification status check failed: {$e->getMessage()}");
            return false; // Conservador: assume não verificado
        }
    }

    /**
     * Verifica se número precisa de registro
     * 
     * @return bool
     */
    protected function phoneNumberNeedsRegistration(): bool
    {
        try {
            $healthService = new HealthService($this->channel);
            $healthData = $healthService->fetchHealthStatus();

            // Verifica se está em estado pendente
            return ($healthData['platform_type'] ?? '') === 'NOT_APPLICABLE' ||
                   ($healthData['throughput']['level'] ?? '') === 'NOT_APPLICABLE';
        } catch (\Exception $e) {
            Log::error("[WHATSAPP] Phone registration check failed: {$e->getMessage()}");
            return false; // Conservador: não registra se não conseguir determinar
        }
    }
}

