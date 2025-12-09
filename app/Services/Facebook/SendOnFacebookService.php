<?php

namespace App\Services\Facebook;

use App\Models\Channel\FacebookChannel;
use App\Services\Base\SendMessageServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Serviço SendOnFacebookService
 * 
 * Envia mensagens via Facebook Messenger API.
 * Implementa SendMessageServiceInterface seguindo SOLID.
 * 
 * @package App\Services\Facebook
 */
class SendOnFacebookService implements SendMessageServiceInterface
{
    protected FacebookChannel $channel;
    protected FacebookApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param FacebookChannel $channel
     */
    public function __construct(FacebookChannel $channel)
    {
        $this->channel = $channel;
        $this->apiClient = new FacebookApiClient($channel->page_access_token);
    }

    /**
     * Envia mensagem de texto
     * 
     * @param string $recipientId ID do destinatário
     * @param string $message Texto da mensagem
     * @return array Resposta da API
     * @throws \Exception
     */
    public function sendText(string $recipientId, string $message): array
    {
        try {
            return $this->apiClient->sendTextMessage($recipientId, $message, $this->channel->page_id);
        } catch (\Exception $e) {
            Log::error("[FACEBOOK] Failed to send message: {$e->getMessage()}", [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
            ]);
            throw $e;
        }
    }

    /**
     * Envia anexo (imagem, vídeo, áudio, arquivo)
     * 
     * @param string $recipientId ID do destinatário
     * @param string $attachmentUrl URL pública do anexo
     * @param string $attachmentType Tipo: 'image', 'video', 'audio', 'file'
     * @return array Resposta da API
     * @throws \Exception
     */
    public function sendAttachment(string $recipientId, string $attachmentUrl, string $attachmentType = 'file'): array
    {
        try {
            return $this->apiClient->sendAttachmentMessage(
                $recipientId,
                $attachmentUrl,
                $attachmentType,
                $this->channel->page_id
            );
        } catch (\Exception $e) {
            Log::error("[FACEBOOK] Failed to send attachment: {$e->getMessage()}", [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
                'attachment_type' => $attachmentType,
            ]);
            throw $e;
        }
    }

    /**
     * Envia mensagem (interface)
     * 
     * @param \App\Models\Message $message
     * @return void
     */
    public function send(\App\Models\Message $message): void
    {
        // Obtém o ID do destinatário (source_id do ContactInbox)
        $contactInbox = \App\Models\ContactInbox::where('contact_id', $message->conversation->contact_id)
            ->where('inbox_id', $message->inbox_id)
            ->first();

        if (!$contactInbox || !$contactInbox->source_id) {
            Log::error('[FACEBOOK SERVICE] ContactInbox source_id not found', [
                'message_id' => $message->id,
                'contact_id' => $message->conversation->contact_id,
                'inbox_id' => $message->inbox_id
            ]);
            throw new \Exception('ContactInbox source_id not found');
        }

        $recipientId = $contactInbox->source_id;

        // Envia anexos se houver
        if ($message->attachments && $message->attachments->isNotEmpty()) {
            foreach ($message->attachments as $attachment) {
                $type = match($attachment->file_type) {
                        'image' => 'image',
                        'audio' => 'audio',
                        'video' => 'video',
                        default => 'file',
                    };
                    
                    // Use download_url (proxied via backend) instead of file_url (direct storage)
                    // This ensures Facebook can access the file even if storage is MinIO/Localhost
                    // as long as the backend is exposed via a public URL (e.g., ngrok)
                    $this->sendAttachment(
                        $recipientId,
                        $attachment->download_url,
                        $type
                    );
                }
            }
        
        // Envia texto se houver
        if ($message->content) {
            $response = $this->sendText($recipientId, $message->content);
            
            // Atualiza source_id da mensagem com o ID retornado pelo Facebook
            if (isset($response['message_id'])) {
                $message->update(['source_id' => $response['message_id']]);
            }
        }
    }
}

