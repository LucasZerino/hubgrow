<?php

namespace App\Services\Webhooks;

use App\Jobs\Webhooks\WebhookJob;
use App\Models\Channel\InstagramChannel;
use App\Models\Inbox;
use Illuminate\Support\Facades\Log;

/**
 * Serviço WebhookDispatcher
 * 
 * Dispara webhooks para o frontend quando eventos ocorrem.
 * Similar ao WebhookListener do Chatwoot.
 * 
 * @package App\Services\Webhooks
 */
class WebhookDispatcher
{
    /**
     * Dispara webhook para o canal do inbox (se configurado)
     * 
     * @param Inbox $inbox
     * @param array $payload
     * @param string|null $webhookType
     * @return void
     */
    public static function dispatchForInbox(Inbox $inbox, array $payload, ?string $webhookType = null): void
    {
        $dispatcher = new self();
        $dispatcher->sendForInbox($inbox, $payload, $webhookType);
    }

    /**
     * Envia webhook para o inbox
     * 
     * @param Inbox $inbox
     * @param array $payload
     * @param string|null $webhookType
     * @return void
     */
    protected function sendForInbox(Inbox $inbox, array $payload, ?string $webhookType = null): void
    {
        Log::info('[WEBHOOK DISPATCHER] Iniciando dispatch para inbox', [
            'inbox_id' => $inbox->id,
            'inbox_name' => $inbox->name,
            'event' => $payload['event'] ?? 'unknown',
        ]);

        // Busca webhook_url do canal
        $webhookUrl = $this->getWebhookUrlFromChannel($inbox);
        
        Log::info('[WEBHOOK DISPATCHER] Webhook URL obtida', [
            'webhook_url' => $webhookUrl ? $this->maskUrl($webhookUrl) : 'null',
            'has_webhook' => !empty($webhookUrl),
        ]);
        
        if (!$webhookUrl) {
            Log::info('[WEBHOOK DISPATCHER] Webhook URL não configurada, abortando dispatch');
            return; // Sem webhook configurado, não faz nada
        }

        // Adiciona inbox_id ao payload se não estiver presente
        if (!isset($payload['inbox'])) {
            $payload['inbox'] = [
                'id' => $inbox->id,
                'name' => $inbox->name,
            ];
        }

        Log::info('[WEBHOOK DISPATCHER] Enfileirando job de webhook', [
            'webhook_url' => $this->maskUrl($webhookUrl),
            'payload_keys' => array_keys($payload),
        ]);

        // Enfileira job para envio assíncrono
        WebhookJob::dispatch($webhookUrl, $payload, $webhookType)->onQueue('low');
        
        Log::info('[WEBHOOK DISPATCHER] Job de webhook enfileirado com sucesso', [
            'inbox_id' => $inbox->id,
            'event' => $payload['event'] ?? 'unknown',
            'webhook_url' => $this->maskUrl($webhookUrl),
        ]);
    }

    /**
     * Busca webhook_url do canal associado ao inbox
     * 
     * @param Inbox $inbox
     * @return string|null
     */
    protected function getWebhookUrlFromChannel(Inbox $inbox): ?string
    {
        Log::info('[WEBHOOK DISPATCHER] Buscando webhook URL do canal', [
            'inbox_id' => $inbox->id,
            'channel_type' => $inbox->channel_type,
        ]);

        $channel = $inbox->channel;
        
        if (!$channel) {
            Log::warning('[WEBHOOK DISPATCHER] Canal não encontrado para inbox', [
                'inbox_id' => $inbox->id,
                'channel_type' => $inbox->channel_type,
            ]);
            return null;
        }

        Log::info('[WEBHOOK DISPATCHER] Canal encontrado', [
            'channel_class' => get_class($channel),
            'channel_id' => $channel->id ?? null,
        ]);

        // Instagram Channel
        if ($channel instanceof InstagramChannel) {
            $webhookUrl = $channel->webhook_url;
            Log::info('[WEBHOOK DISPATCHER] Instagram Channel - webhook URL', [
                'webhook_url' => $webhookUrl ? $this->maskUrl($webhookUrl) : 'null',
            ]);
            return $webhookUrl;
        }

        Log::warning('[WEBHOOK DISPATCHER] Tipo de canal não suportado para webhook', [
            'channel_class' => get_class($channel),
        ]);

        // Outros tipos de canal podem ter webhook_url também
        // Adicionar conforme necessário (WhatsApp, Facebook, etc.)
        
        return null;
    }

    /**
     * Mascara URL para logs
     * 
     * @param string $url
     * @return string
     */
    protected function maskUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return 'invalid-url';
        }

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        
        return sprintf('%s://%s%s', $scheme, $host, $path);
    }

    /**
     * Prepara payload de mensagem para webhook
     * 
     * @param \App\Models\Message $message
     * @param string $event
     * @return array
     */
    public static function prepareMessagePayload(\App\Models\Message $message, string $event): array
    {
        // Sempre recarrega os relacionamentos para garantir dados atualizados
        // Especialmente importante para attachments que podem ser criados após a mensagem
        $message->load(['attachments', 'conversation.contact']);

        $attachments = $message->attachments->map(function ($attachment) {
            return $attachment->toArray();
        })->toArray();
    
        $contactData = null;
        if ($message->conversation && $message->conversation->contact) {
            $contact = $message->conversation->contact;
            $contactData = [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone_number' => $contact->phone_number,
                'identifier_instagram' => $contact->identifier_instagram,
                'identifier_facebook' => $contact->identifier_facebook,
                'avatar_url' => $contact->avatar_url,
            ];
        }
    
        $computedContentType = $message->content_type;
        if ((!$message->content || $message->content === '') && !empty($attachments)) {
            $first = $message->attachments->first();
            if ($first) {
                $computedContentType = $first->file_type_name;
            }
        }

        return [
            'event' => $event,
            'id' => $message->id,
            'content' => $message->content,
            'content_type' => $computedContentType,
            'message_type' => $message->message_type === \App\Models\Message::TYPE_INCOMING ? 'incoming' : 'outgoing',
            'status' => $message->status,
            'source_id' => $message->source_id,
            'created_at' => $message->created_at?->toIso8601String(),
            'attachments' => $attachments,
            'contact' => $contactData,
            'conversation' => [
                'id' => $message->conversation_id,
                'display_id' => $message->conversation?->display_id,
                'status' => $message->conversation?->status,
            ],
            'inbox' => [
                'id' => $message->inbox_id,
                'name' => $message->inbox?->name,
            ],
            'account' => [
                'id' => $message->account_id,
            ],
        ];
    }

    /**
     * Prepara payload de conversa para webhook
     * 
     * @param \App\Models\Conversation $conversation
     * @param string $event
     * @return array
     */
    public static function prepareConversationPayload(\App\Models\Conversation $conversation, string $event): array
    {
        return [
            'event' => $event,
            'id' => $conversation->id,
            'display_id' => $conversation->display_id,
            'status' => $conversation->status,
            'priority' => $conversation->priority,
            'assignee_id' => $conversation->assignee_id,
            'last_activity_at' => $conversation->last_activity_at?->toIso8601String(),
            'created_at' => $conversation->created_at?->toIso8601String(),
            'inbox' => [
                'id' => $conversation->inbox_id,
                'name' => $conversation->inbox?->name,
            ],
            'contact' => [
                'id' => $conversation->contact_id,
                'name' => $conversation->contact?->name,
            ],
            'account' => [
                'id' => $conversation->account_id,
            ],
        ];
    }
}

