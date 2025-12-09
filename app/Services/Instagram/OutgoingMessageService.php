<?php

namespace App\Services\Instagram;

use App\Models\Channel\InstagramChannel;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Serviço OutgoingMessageService
 * 
 * Envia mensagens e mídias para Instagram via Graph API.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Instagram
 */
class OutgoingMessageService
{
    protected InstagramChannel $channel;
    protected InstagramApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param InstagramChannel $channel
     */
    public function __construct(InstagramChannel $channel)
    {
        $this->channel = $channel;
        $accessToken = $channel->getAccessToken();
        $this->apiClient = new InstagramApiClient($accessToken);
    }

    /**
     * Envia mensagem de texto
     * 
     * @param string $recipientId ID do destinatário (Instagram ID)
     * @param string $content Conteúdo da mensagem
     * @return array Resposta da API
     * @throws \Exception
     */
    public function sendTextMessage(string $recipientId, string $content): array
    {
        try {
            $response = $this->apiClient->sendTextMessage($recipientId, $content);
            
            Log::info('[INSTAGRAM] Text message sent', [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
                'message_id' => $response['message_id'] ?? null,
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM] Failed to send text message', [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Envia imagem
     * 
     * @param string $recipientId ID do destinatário
     * @param string $imageUrl URL da imagem (pode ser local ou S3/MinIO)
     * @param string|null $caption Legenda opcional
     * @return array Resposta da API
     * @throws \Exception
     */
    public function sendImage(string $recipientId, string $imageUrl, ?string $caption = null): array
    {
        try {
            // Se for URL local/MinIO, converte para URL pública
            $publicUrl = $this->ensurePublicUrl($imageUrl);
            
            $response = $this->apiClient->sendMediaMessage(
                $recipientId,
                'image',
                $publicUrl,
                $caption
            );
            
            Log::info('[INSTAGRAM] Image sent', [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
                'message_id' => $response['message_id'] ?? null,
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM] Failed to send image', [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Envia vídeo
     * 
     * @param string $recipientId ID do destinatário
     * @param string $videoUrl URL do vídeo (pode ser local ou S3/MinIO)
     * @param string|null $caption Legenda opcional
     * @return array Resposta da API
     * @throws \Exception
     */
    public function sendVideo(string $recipientId, string $videoUrl, ?string $caption = null): array
    {
        try {
            // Se for URL local/MinIO, converte para URL pública
            $publicUrl = $this->ensurePublicUrl($videoUrl);
            
            $response = $this->apiClient->sendMediaMessage(
                $recipientId,
                'video',
                $publicUrl,
                $caption
            );
            
            Log::info('[INSTAGRAM] Video sent', [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
                'message_id' => $response['message_id'] ?? null,
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM] Failed to send video', [
                'channel_id' => $this->channel->id,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Garante que a URL é pública (converte local/MinIO para URL pública se necessário)
     * 
     * @param string $url
     * @return string
     */
    protected function ensurePublicUrl(string $url): string
    {
        // Se já for URL HTTP/HTTPS completa, retorna como está
        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        // Se for caminho de storage, gera URL pública
        if (Storage::exists($url)) {
            return Storage::url($url);
        }

        // Se for caminho relativo, assume que está em storage público
        return Storage::url(ltrim($url, '/'));
    }
}

