<?php

namespace App\Services\WebWidget;

use App\Models\Account;
use App\Models\Channel\WebWidgetChannel;
use App\Models\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Serviço ChannelCreationService
 * 
 * Cria canal WebWidget e inbox associado.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\WebWidget
 */
class ChannelCreationService
{
    protected Account $account;
    protected array $params;

    /**
     * Construtor
     * 
     * @param Account $account
     * @param array $params Parâmetros do canal
     */
    public function __construct(Account $account, array $params)
    {
        $this->account = $account;
        $this->params = $params;
    }

    /**
     * Executa a criação do canal
     * 
     * @return WebWidgetChannel
     * @throws \Exception
     */
    public function perform(): WebWidgetChannel
    {
        $this->validateParameters();

        return DB::transaction(function () {
            $channel = $this->createChannel();
            $this->createInbox($channel);
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

        if (empty($this->params['website_url'])) {
            throw new \Exception('Website URL is required');
        }
    }

    /**
     * Cria canal WebWidget
     * 
     * @return WebWidgetChannel
     */
    protected function createChannel(): WebWidgetChannel
    {
        $defaults = [
            'widget_color' => '#1f93ff',
            'reply_time' => WebWidgetChannel::REPLY_TIME_FEW_MINUTES,
            'pre_chat_form_enabled' => false,
            'continuity_via_email' => true,
            'hmac_mandatory' => false,
            'feature_flags' => 7, // attachments, emoji_picker, end_conversation
        ];

        $channelData = array_merge($defaults, $this->params);
        $channelData['account_id'] = $this->account->id;

        // Valida e define pre_chat_form_options se não fornecido
        if (empty($channelData['pre_chat_form_options'])) {
            $channelData['pre_chat_form_options'] = $this->getDefaultPreChatFormOptions();
        }

        return WebWidgetChannel::create($channelData);
    }

    /**
     * Cria inbox associado ao canal
     * 
     * @param WebWidgetChannel $channel
     * @return Inbox
     */
    protected function createInbox(WebWidgetChannel $channel): Inbox
    {
        $inboxName = $this->params['inbox_name'] ?? 'Website';

        $inbox = new Inbox();
        $inbox->account_id = $this->account->id;
        $inbox->name = $inboxName;
        $inbox->channel_type = WebWidgetChannel::class;
        $inbox->channel_id = $channel->id;
        $inbox->save();

        return $inbox;
    }

    /**
     * Retorna opções padrão do formulário pré-chat
     * 
     * @return array
     */
    protected function getDefaultPreChatFormOptions(): array
    {
        return [
            'pre_chat_message' => 'Share your queries or comments here.',
            'pre_chat_fields' => [
                [
                    'field_type' => 'standard',
                    'label' => 'Email Id',
                    'name' => 'emailAddress',
                    'type' => 'email',
                    'required' => true,
                    'enabled' => false,
                ],
                [
                    'field_type' => 'standard',
                    'label' => 'Full name',
                    'name' => 'fullName',
                    'type' => 'text',
                    'required' => false,
                    'enabled' => false,
                ],
                [
                    'field_type' => 'standard',
                    'label' => 'Phone number',
                    'name' => 'phoneNumber',
                    'type' => 'text',
                    'required' => false,
                    'enabled' => false,
                ],
            ],
        ];
    }
}

