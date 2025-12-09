<?php

namespace App\Services\WhatsApp;

use App\Models\Account;
use App\Models\Channel\WhatsAppChannel;
use App\Models\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Serviço ChannelCreationService
 * 
 * Cria canal WhatsApp e inbox associado.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WhatsApp
 */
class ChannelCreationService
{
    protected Account $account;
    protected array $wabaInfo;
    protected array $phoneInfo;
    protected string $accessToken;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param array $wabaInfo Informações do WABA
     * @param array $phoneInfo Informações do telefone
     * @param string $accessToken Token de acesso
     */
    public function __construct(Account $account, array $wabaInfo, array $phoneInfo, string $accessToken)
    {
        $this->account = $account;
        $this->wabaInfo = $wabaInfo;
        $this->phoneInfo = $phoneInfo;
        $this->accessToken = $accessToken;
    }

    /**
     * Executa a criação do canal
     * 
     * @return WhatsAppChannel
     * @throws \Exception
     */
    public function perform(): WhatsAppChannel
    {
        $this->validateParameters();

        $existingChannel = $this->findExistingChannel();
        
        if ($existingChannel) {
            throw new \Exception(
                "Phone number already exists: {$existingChannel->phone_number}"
            );
        }

        return $this->createChannelWithInbox();
    }

    /**
     * Valida parâmetros obrigatórios
     * 
     * @return void
     * @throws \Exception
     */
    protected function validateParameters(): void
    {
        if (empty($this->account)) {
            throw new \Exception('Account is required');
        }

        if (empty($this->wabaInfo)) {
            throw new \Exception('WABA info is required');
        }

        if (empty($this->phoneInfo)) {
            throw new \Exception('Phone info is required');
        }

        if (empty($this->accessToken)) {
            throw new \Exception('Access token is required');
        }
    }

    /**
     * Busca canal existente pelo número de telefone
     * 
     * @return WhatsAppChannel|null
     */
    protected function findExistingChannel(): ?WhatsAppChannel
    {
        return WhatsAppChannel::where('phone_number', $this->phoneInfo['phone_number'])
            ->first();
    }

    /**
     * Cria canal e inbox em transação
     * 
     * @return WhatsAppChannel
     */
    protected function createChannelWithInbox(): WhatsAppChannel
    {
        return DB::transaction(function () {
            $channel = $this->buildChannel();
            $this->createInbox($channel);
            return $channel;
        });
    }

    /**
     * Constrói o canal WhatsApp
     * 
     * @return WhatsAppChannel
     */
    protected function buildChannel(): WhatsAppChannel
    {
        return WhatsAppChannel::create([
            'account_id' => $this->account->id,
            'phone_number' => $this->phoneInfo['phone_number'],
            'provider' => WhatsAppChannel::PROVIDER_WHATSAPP_CLOUD,
            'provider_config' => $this->buildProviderConfig(),
        ]);
    }

    /**
     * Constrói configuração do provider
     * 
     * @return array
     */
    protected function buildProviderConfig(): array
    {
        return [
            'api_key' => $this->accessToken,
            'phone_number_id' => $this->phoneInfo['phone_number_id'],
            'business_account_id' => $this->wabaInfo['waba_id'],
            'source' => 'embedded_signup',
        ];
    }

    /**
     * Cria inbox associado ao canal
     * 
     * @param WhatsAppChannel $channel
     * @return Inbox
     */
    protected function createInbox(WhatsAppChannel $channel): Inbox
    {
        $inboxName = $this->buildInboxName();

        $inbox = new Inbox();
        $inbox->account_id = $this->account->id;
        $inbox->name = $inboxName;
        $inbox->channel_type = WhatsAppChannel::class;
        $inbox->channel_id = $channel->id;
        $inbox->save();

        return $inbox;
    }

    /**
     * Constrói nome do inbox
     * 
     * @return string
     */
    protected function buildInboxName(): string
    {
        $businessName = $this->phoneInfo['business_name'] 
                     ?? $this->wabaInfo['business_name'] 
                     ?? 'WhatsApp';

        return "{$businessName} WhatsApp";
    }
}

