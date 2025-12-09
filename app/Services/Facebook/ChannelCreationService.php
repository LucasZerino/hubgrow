<?php

namespace App\Services\Facebook;

use App\Models\Account;
use App\Models\Channel\FacebookChannel;
use App\Models\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Serviço ChannelCreationService
 * 
 * Cria canal Facebook e inbox associado.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Facebook
 */
class ChannelCreationService
{
    protected Account $account;
    protected string $pageId;
    protected string $pageAccessToken;
    protected string $userAccessToken;
    protected string $inboxName;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param string $pageId ID da página
     * @param string $pageAccessToken Token de acesso da página
     * @param string $userAccessToken Token de acesso do usuário
     * @param string $inboxName Nome do inbox
     */
    public function __construct(
        Account $account,
        string $pageId,
        string $pageAccessToken,
        string $userAccessToken,
        string $inboxName
    ) {
        $this->account = $account;
        $this->pageId = $pageId;
        $this->pageAccessToken = $pageAccessToken;
        $this->userAccessToken = $userAccessToken;
        $this->inboxName = $inboxName;
    }

    /**
     * Executa a criação do canal
     * 
     * @return FacebookChannel
     * @throws \Exception
     */
    public function perform(): FacebookChannel
    {
        $this->validateParameters();

        return DB::transaction(function () {
            $channel = $this->createChannel();
            $this->createInbox($channel);
            $this->setInstagramId($channel);
            
            // Configura webhooks do Facebook
            try {
                $channel->setupWebhooks();
                Log::info('[FACEBOOK] Webhooks configurados', [
                    'channel_id' => $channel->id,
                    'page_id' => $channel->page_id,
                ]);
            } catch (\Exception $e) {
                Log::warning('[FACEBOOK] Falha ao configurar webhooks', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Marca como reautorizado (limpa flags de erro)
            $channel->markAsReauthorized();
            
            return $channel;
        });
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

        if (empty($this->pageId)) {
            throw new \Exception('Page ID is required');
        }

        if (empty($this->pageAccessToken)) {
            throw new \Exception('Page access token is required');
        }

        if (empty($this->userAccessToken)) {
            throw new \Exception('User access token is required');
        }
    }

    /**
     * Cria canal Facebook
     * 
     * @return FacebookChannel
     */
    protected function createChannel(): FacebookChannel
    {
        return FacebookChannel::create([
            'account_id' => $this->account->id,
            'page_id' => $this->pageId,
            'page_access_token' => $this->pageAccessToken,
            'user_access_token' => $this->userAccessToken,
        ]);
    }

    /**
     * Cria inbox associado ao canal
     * 
     * @param FacebookChannel $channel
     * @return Inbox
     */
    protected function createInbox(FacebookChannel $channel): Inbox
    {
        $inbox = new Inbox();
        $inbox->account_id = $this->account->id;
        $inbox->name = $this->inboxName;
        $inbox->channel_type = FacebookChannel::class;
        $inbox->channel_id = $channel->id;
        $inbox->save();

        return $inbox;
    }

    /**
     * Busca e define Instagram ID se a página tiver Instagram Business Account
     * 
     * @param FacebookChannel $channel
     * @return void
     */
    protected function setInstagramId(FacebookChannel $channel): void
    {
        try {
            $apiClient = new FacebookApiClient($channel->page_access_token);
            $instagramId = $apiClient->fetchInstagramBusinessAccount($channel->page_id, $channel->page_access_token);
            
            if ($instagramId) {
                $channel->update(['instagram_id' => $instagramId]);
            }
        } catch (\Exception $e) {
            Log::warning("[FACEBOOK] Failed to set Instagram ID: {$e->getMessage()}");
        }
    }
}

