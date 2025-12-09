<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\Channel\WhatsAppChannel;
use Illuminate\Support\Facades\Log;

/**
 * Serviço EmbeddedSignupService
 * 
 * Orquestra o processo completo de embedded signup do WhatsApp.
 * Coordena todos os serviços necessários seguindo SOLID.
 * 
 * @package App\Services\WhatsApp
 */
class EmbeddedSignupService
{
    protected Account $account;
    protected string $code;
    protected string $businessId;
    protected string $wabaId;
    protected ?string $phoneNumberId;
    protected ?int $inboxId;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param array $params Parâmetros do embedded signup
     * @param int|null $inboxId ID do inbox (para reautorização)
     */
    public function __construct(Account $account, array $params, ?int $inboxId = null)
    {
        $this->account = $account;
        $this->code = $params['code'] ?? '';
        $this->businessId = $params['business_id'] ?? '';
        $this->wabaId = $params['waba_id'] ?? '';
        $this->phoneNumberId = $params['phone_number_id'] ?? null;
        $this->inboxId = $inboxId;
    }

    /**
     * Executa o processo completo de embedded signup
     * 
     * @return WhatsAppChannel
     * @throws \Exception
     */
    public function perform(): WhatsAppChannel
    {
        $this->validateParameters();

        // 1. Troca código por access token
        $accessToken = $this->exchangeCodeForToken();

        // 2. Busca informações do telefone
        $phoneInfo = $this->fetchPhoneInfo($accessToken);

        // 3. Valida acesso do token ao WABA
        $this->validateTokenAccess($accessToken);

        // 4. Cria ou reautoriza canal
        $channel = $this->createOrReauthorizeChannel($accessToken, $phoneInfo);

        // 5. Configura webhooks
        $channel->setupWebhooks();

        // 6. Verifica saúde do canal (opcional)
        $this->checkChannelHealth($channel);

        return $channel;
    }

    /**
     * Valida parâmetros obrigatórios
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateParameters(): void
    {
        $missing = [];

        if (empty($this->code)) {
            $missing[] = 'code';
        }

        if (empty($this->businessId)) {
            $missing[] = 'business_id';
        }

        if (empty($this->wabaId)) {
            $missing[] = 'waba_id';
        }

        if (!empty($missing)) {
            throw new \Exception("Required parameters are missing: " . implode(', ', $missing));
        }
    }

    /**
     * Troca código por access token
     * 
     * @return string
     */
    protected function exchangeCodeForToken(): string
    {
        $service = new TokenExchangeService($this->code);
        return $service->perform();
    }

    /**
     * Busca informações do telefone
     * 
     * @param string $accessToken
     * @return array
     */
    protected function fetchPhoneInfo(string $accessToken): array
    {
        $service = new PhoneInfoService($this->wabaId, $this->phoneNumberId, $accessToken);
        return $service->perform();
    }

    /**
     * Valida acesso do token ao WABA
     * 
     * @param string $accessToken
     * @return void
     */
    protected function validateTokenAccess(string $accessToken): void
    {
        $service = new TokenValidationService($accessToken, $this->wabaId);
        $service->perform();
    }

    /**
     * Cria ou reautoriza canal
     * 
     * @param string $accessToken
     * @param array $phoneInfo
     * @return WhatsAppChannel
     */
    protected function createOrReauthorizeChannel(string $accessToken, array $phoneInfo): WhatsAppChannel
    {
        if ($this->inboxId) {
            // Reautorização
            $service = new ReauthorizationService(
                $this->account,
                $this->inboxId,
                $this->phoneNumberId ?? $phoneInfo['phone_number_id'],
                $this->businessId
            );
            return $service->perform($accessToken, $phoneInfo);
        } else {
            // Criação nova
            $wabaInfo = [
                'waba_id' => $this->wabaId,
                'business_name' => $phoneInfo['business_name'] ?? '',
            ];
            
            $service = new ChannelCreationService($this->account, $wabaInfo, $phoneInfo, $accessToken);
            return $service->perform();
        }
    }

    /**
     * Verifica saúde do canal
     * 
     * @param WhatsAppChannel $channel
     * @return void
     */
    protected function checkChannelHealth(WhatsAppChannel $channel): void
    {
        try {
            $healthService = new HealthService($channel);
            $healthData = $healthService->fetchHealthStatus();

            // Log para monitoramento
            Log::info("[WHATSAPP] Channel health status", [
                'channel_id' => $channel->id,
                'health_data' => $healthData,
            ]);
        } catch (\Exception $e) {
            Log::warning("[WHATSAPP] Health check failed: {$e->getMessage()}");
            // Não bloqueia o processo se health check falhar
        }
    }
}

