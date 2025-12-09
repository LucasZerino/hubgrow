<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\Channel\WhatsAppChannel;
use App\Models\Inbox;
use Illuminate\Support\Facades\Log;

/**
 * Serviço ReauthorizationService
 * 
 * Reautoriza um canal WhatsApp existente.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class ReauthorizationService
{
    protected Account $account;
    protected int $inboxId;
    protected string $phoneNumberId;
    protected string $businessId;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param int $inboxId ID do inbox
     * @param string $phoneNumberId ID do número de telefone
     * @param string $businessId ID do business account
     */
    public function __construct(Account $account, int $inboxId, string $phoneNumberId, string $businessId)
    {
        $this->account = $account;
        $this->inboxId = $inboxId;
        $this->phoneNumberId = $phoneNumberId;
        $this->businessId = $businessId;
    }

    /**
     * Executa a reautorização
     * 
     * @param string $accessToken Novo token de acesso
     * @param array $phoneInfo Informações do telefone
     * @return WhatsAppChannel
     * @throws \Exception
     */
    public function perform(string $accessToken, array $phoneInfo): WhatsAppChannel
    {
        $inbox = $this->account->inboxes()->findOrFail($this->inboxId);
        $channel = $inbox->channel;

        if (!($channel instanceof WhatsAppChannel)) {
            throw new \Exception('Channel is not a WhatsApp channel');
        }

        // Valida se o número de telefone corresponde
        if ($phoneInfo['phone_number'] !== $channel->phone_number) {
            throw new \Exception(
                "Phone number mismatch. Expected {$channel->phone_number}, " .
                "got {$phoneInfo['phone_number']}"
            );
        }

        $this->updateChannelConfig($channel, $accessToken, $phoneInfo);

        return $channel;
    }

    /**
     * Atualiza configuração do canal
     * 
     * @param WhatsAppChannel $channel
     * @param string $accessToken
     * @param array $phoneInfo
     * @return void
     */
    protected function updateChannelConfig(WhatsAppChannel $channel, string $accessToken, array $phoneInfo): void
    {
        $currentConfig = $channel->provider_config ?? [];
        
        $channel->provider_config = array_merge($currentConfig, [
            'api_key' => $accessToken,
            'phone_number_id' => $this->phoneNumberId,
            'business_account_id' => $this->businessId,
            'source' => 'embedded_signup',
        ]);
        
        $channel->save();

        // Atualiza nome do inbox se o business name mudou
        $businessName = $phoneInfo['business_name'] ?? $phoneInfo['verified_name'] ?? null;
        
        if ($businessName && $channel->inbox) {
            $channel->inbox->update(['name' => "{$businessName} WhatsApp"]);
        }
    }
}

