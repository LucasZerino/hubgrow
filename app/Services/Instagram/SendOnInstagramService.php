<?php

namespace App\Services\Instagram;

use App\Models\Message;
use App\Models\Channel\InstagramChannel;
use App\Services\Base\SendMessageServiceInterface;
use Illuminate\Support\Facades\Log;

/**
 * Serviço SendOnInstagramService
 * 
 * Envia mensagens via Instagram Graph API.
 * Similar ao Instagram::SendOnInstagramService do Chatwoot.
 * 
 * @package App\Services\Instagram
 */
class SendOnInstagramService
{
    protected Message $message;
    protected InstagramChannel $channel;
    protected InstagramApiClient $apiClient;

    /**
     * Construtor
     * 
     * @param Message $message
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->channel = $message->conversation->inbox->channel;
        
        if (!$this->channel instanceof InstagramChannel) {
            throw new \InvalidArgumentException('Channel is not an Instagram channel');
        }
        
        $this->apiClient = new InstagramApiClient($this->channel->getAccessToken());
    }

    /**
     * Executa o envio da mensagem
     * 
     * @return void
     */
    public function perform(): void
    {
        $this->validateMessage();
        
        error_log('[INSTAGRAM SEND] ====== PERFORM CHAMADO ======');
        error_log('[INSTAGRAM SEND] Message ID: ' . $this->message->id);
        error_log('[INSTAGRAM SEND] Content: "' . ($this->message->content ?: 'VAZIO') . '"');
        error_log('[INSTAGRAM SEND] Content Type: ' . $this->message->content_type);
        error_log('[INSTAGRAM SEND] Attachments count: ' . ($this->message->attachments ? $this->message->attachments->count() : 0));
        
        // Envia anexos primeiro (se houver)
        if ($this->message->attachments && $this->message->attachments->isNotEmpty()) {
            error_log('[INSTAGRAM SEND] ✅ Tem attachments, enviando...');
            $this->sendAttachments();
        } else {
            error_log('[INSTAGRAM SEND] ⚠️ Nenhum attachment encontrado');
        }
        
        // Envia conteúdo de texto depois (se houver)
        // No Instagram, texto e anexos são enviados separadamente
        if ($this->message->content) {
            error_log('[INSTAGRAM SEND] ✅ Tem conteúdo, enviando texto...');
            $this->sendContent();
        } else {
            error_log('[INSTAGRAM SEND] ⚠️ Nenhum conteúdo de texto');
        }
        
        error_log('[INSTAGRAM SEND] ====== PERFORM CONCLUÍDO ======');
    }

    /**
     * Valida se a mensagem pode ser enviada
     * 
     * @return void
     */
    protected function validateMessage(): void
    {
        if ($this->message->message_type !== Message::TYPE_OUTGOING) {
            throw new \InvalidArgumentException('Message is not outgoing');
        }

        if ($this->message->private) {
            throw new \InvalidArgumentException('Private messages cannot be sent');
        }

        if ($this->message->source_id) {
            throw new \InvalidArgumentException('Message already has source_id (already sent)');
        }
    }

    /**
     * Envia conteúdo de texto
     * 
     * @return void
     */
    protected function sendContent(): void
    {
        $recipientId = $this->getRecipientId();
        $content = $this->message->content;

        Log::info('[INSTAGRAM SEND] Sending text message', [
            'message_id' => $this->message->id,
            'recipient_id' => $recipientId,
            'channel_id' => $this->channel->id,
        ]);

        try {
            // Verifica se deve usar tag HUMAN_AGENT (similar ao Chatwoot: merge_human_agent_tag)
            $useHumanAgentTag = $this->shouldUseHumanAgentTag();
            
            // Usa o instagram_id do canal na URL da API
            $response = $this->apiClient->sendTextMessage(
                $recipientId, 
                $content, 
                $this->channel->instagram_id,
                $useHumanAgentTag
            );
            
            // Processa resposta (similar ao Chatwoot: process_response)
            $parsedResponse = $response;
            if (isset($parsedResponse['message_id']) && !isset($parsedResponse['error'])) {
                // Atualiza mensagem com source_id e status
                error_log('[INSTAGRAM SEND] Atualizando mensagem com status SENT...');
                error_log('[INSTAGRAM SEND] Message ID: ' . $this->message->id);
                error_log('[INSTAGRAM SEND] Status atual: ' . $this->message->status);
                error_log('[INSTAGRAM SEND] Novo status: ' . Message::STATUS_SENT);
                
                error_log('[INSTAGRAM SEND] Chamando update() na mensagem...');
                $this->message->update([
                    'source_id' => $parsedResponse['message_id'],
                    'status' => Message::STATUS_SENT,
                ]);
                
                error_log('[INSTAGRAM SEND] ✅ Mensagem atualizada com sucesso');
                error_log('[INSTAGRAM SEND] Status após update: ' . $this->message->fresh()->status);
                error_log('[INSTAGRAM SEND] Verificando se observer foi chamado...');
                
                // Força refresh para garantir que os dados estão atualizados
                $this->message->refresh();
                error_log('[INSTAGRAM SEND] Mensagem após refresh - Status: ' . $this->message->status);

                Log::info('[INSTAGRAM SEND] Message sent successfully', [
                    'message_id' => $this->message->id,
                    'source_id' => $parsedResponse['message_id'],
                    'status' => $this->message->fresh()->status,
                ]);
            } else {
                // Erro na resposta
                $this->handleError($parsedResponse, $content);
            }
        } catch (\Exception $e) {
            Log::error('[INSTAGRAM SEND] Failed to send message', [
                'message_id' => $this->message->id,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
            ]);

            // Atualiza status para falha (similar ao Chatwoot: Messages::StatusUpdateService)
            $this->handleError(['error' => ['message' => $e->getMessage()]], $content);

            throw $e;
        }
    }

    /**
     * Obtém o ID do destinatário (Instagram ID do contato)
     * 
     * @return string
     */
    protected function getRecipientId(): string
    {
        $conversation = $this->message->conversation;
        
        if (!$conversation->contact_id) {
            Log::error('[INSTAGRAM SEND] Contact not found for conversation', [
                'message_id' => $this->message->id,
                'conversation_id' => $conversation->id,
            ]);
            throw new \RuntimeException('Contact not found for conversation');
        }

        // Busca o source_id do contato para este inbox (Instagram ID)
        $contactInbox = \App\Models\ContactInbox::where('contact_id', $conversation->contact_id)
            ->where('inbox_id', $conversation->inbox_id)
            ->first();

        if (!$contactInbox) {
            Log::error('[INSTAGRAM SEND] ContactInbox not found', [
                'message_id' => $this->message->id,
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'inbox_id' => $conversation->inbox_id,
            ]);
            throw new \RuntimeException('ContactInbox not found for conversation');
        }

        if (!$contactInbox->source_id) {
            Log::error('[INSTAGRAM SEND] Contact source_id is empty', [
                'message_id' => $this->message->id,
                'conversation_id' => $conversation->id,
                'contact_inbox_id' => $contactInbox->id,
            ]);
            throw new \RuntimeException('Contact source_id (Instagram ID) not found for Instagram inbox');
        }

        Log::info('[INSTAGRAM SEND] Recipient ID obtained', [
            'message_id' => $this->message->id,
            'recipient_id' => $contactInbox->source_id,
            'contact_inbox_id' => $contactInbox->id,
        ]);

        return $contactInbox->source_id;
    }

    /**
     * Envia anexos
     * Similar ao Chatwoot: send_attachments
     * 
     * @return void
     */
    protected function sendAttachments(): void
    {
        $recipientId = $this->getRecipientId();
        
        error_log('[INSTAGRAM SEND] sendAttachments chamado');
        error_log('[INSTAGRAM SEND] Recipient ID: ' . $recipientId);
        error_log('[INSTAGRAM SEND] Total de attachments: ' . $this->message->attachments->count());

        foreach ($this->message->attachments as $index => $attachment) {
            error_log('[INSTAGRAM SEND] Processando attachment ' . ($index + 1) . ':');
            error_log('[INSTAGRAM SEND]   - ID: ' . $attachment->id);
            error_log('[INSTAGRAM SEND]   - file_type: ' . $attachment->file_type);
            error_log('[INSTAGRAM SEND]   - file_name: ' . ($attachment->file_name ?: 'VAZIO'));
            error_log('[INSTAGRAM SEND]   - file_path: ' . ($attachment->file_path ?: 'VAZIO'));
            
            try {
                $this->sendAttachment($attachment, $recipientId);
            } catch (\Exception $e) {
                error_log('[INSTAGRAM SEND] ❌ ERRO ao enviar anexo: ' . $e->getMessage());
                error_log('[INSTAGRAM SEND] Stack trace: ' . $e->getTraceAsString());
                Log::error('[INSTAGRAM SEND] Erro ao enviar anexo', [
                    'message_id' => $this->message->id,
                    'attachment_id' => $attachment->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continua com próximo anexo mesmo se um falhar
            }
        }
    }

    /**
     * Envia um anexo individual
     * Similar ao Chatwoot: attachment_message_params
     * 
     * @param \App\Models\Attachment $attachment
     * @param string $recipientId
     * @return void
     */
    protected function sendAttachment(\App\Models\Attachment $attachment, string $recipientId): void
    {
        Log::info('[INSTAGRAM SEND] Enviando anexo', [
            'message_id' => $this->message->id,
            'attachment_id' => $attachment->id,
            'file_type' => $attachment->file_type,
            'recipient_id' => $recipientId,
        ]);

        // Localização não pode ser enviada via Instagram API
        if ($attachment->isLocation()) {
            Log::warning('[INSTAGRAM SEND] Localização não suportada no envio via API', [
                'attachment_id' => $attachment->id,
            ]);
            return;
        }

        // Obtém URL do anexo (deve ser URL pública do MinIO/S3)
        $attachmentUrl = $attachment->downloadUrl();
        
        error_log('[INSTAGRAM SEND] Verificando URL do attachment:');
        error_log('[INSTAGRAM SEND]   - Attachment ID: ' . $attachment->id);
        error_log('[INSTAGRAM SEND]   - file_path: ' . ($attachment->file_path ?: 'VAZIO'));
        error_log('[INSTAGRAM SEND]   - downloadUrl(): ' . ($attachmentUrl ?: 'VAZIO'));
        error_log('[INSTAGRAM SEND]   - file_url: ' . ($attachment->file_url ?: 'VAZIO'));

        if (!$attachmentUrl) {
            error_log('[INSTAGRAM SEND] ❌ URL do anexo não encontrada!');
            Log::warning('[INSTAGRAM SEND] URL do anexo não encontrada', [
                'attachment_id' => $attachment->id,
                'file_path' => $attachment->file_path,
                'file_url' => $attachment->file_url,
                'download_url' => $attachmentUrl,
            ]);
            return;
        }
        
        error_log('[INSTAGRAM SEND] ✅ URL do anexo encontrada: ' . $attachmentUrl);

        // Determina tipo de mídia
        $mediaType = $this->getAttachmentType($attachment);
        
        // Instagram API suporta apenas: image, video, audio
        // Arquivos genéricos (PDF, etc) podem ser rejeitados, mas tentamos enviar como 'file'
        // Se falhar, o erro será tratado no handleError
        if ($mediaType === 'file') {
            Log::info('[INSTAGRAM SEND] Tentando enviar arquivo genérico (pode ser rejeitado pelo Instagram)', [
                'attachment_id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'mime_type' => $attachment->mime_type,
            ]);
        }

        // Verifica se deve usar tag HUMAN_AGENT (similar ao Chatwoot: merge_human_agent_tag)
        $useHumanAgentTag = $this->shouldUseHumanAgentTag();
        
        // Envia mídia via API (similar ao Chatwoot: attachment_message_params)
        // Nota: Instagram não suporta caption em anexos, deve ser mensagem separada
        $response = $this->apiClient->sendMediaMessage(
            $recipientId,
            $mediaType,
            $attachmentUrl,
            $this->channel->instagram_id, // ID da conta Instagram
            null, // Caption não suportado em anexos via API
            $useHumanAgentTag
        );

        // Processa resposta (similar ao Chatwoot: process_response)
        $parsedResponse = $response;
        if (isset($parsedResponse['message_id']) && !isset($parsedResponse['error'])) {
            Log::info('[INSTAGRAM SEND] Anexo enviado com sucesso', [
                'message_id' => $this->message->id,
                'attachment_id' => $attachment->id,
                'response_message_id' => $parsedResponse['message_id'],
            ]);
        } else {
            // Erro na resposta
            $this->handleError($parsedResponse, "Attachment: {$attachment->file_name}");
        }

        // Nota: Não atualizamos source_id aqui porque cada anexo gera uma mensagem separada
        // O source_id deve ser atualizado apenas na mensagem de texto se houver (similar ao Chatwoot)
    }

    /**
     * Obtém tipo de anexo para Instagram API
     * Similar ao Chatwoot: attachment_type
     * 
     * @param \App\Models\Attachment $attachment
     * @return string
     */
    protected function getAttachmentType(\App\Models\Attachment $attachment): string
    {
        // Similar ao Chatwoot: return attachment.file_type if %w[image audio video file].include? attachment.file_type
        $allowedTypes = [
            \App\Models\Attachment::FILE_TYPE_IMAGE => 'image',
            \App\Models\Attachment::FILE_TYPE_AUDIO => 'audio',
            \App\Models\Attachment::FILE_TYPE_VIDEO => 'video',
            \App\Models\Attachment::FILE_TYPE_FILE => 'file',
        ];

        return $allowedTypes[$attachment->file_type] ?? 'file';
    }

    /**
     * Verifica se deve usar tag HUMAN_AGENT
     * Similar ao Chatwoot: merge_human_agent_tag
     * 
     * @return bool
     */
    protected function shouldUseHumanAgentTag(): bool
    {
        // TODO: Implementar verificação de configuração global
        // Similar ao Chatwoot: GlobalConfig.get('ENABLE_INSTAGRAM_CHANNEL_HUMAN_AGENT')
        // Por enquanto, retorna false
        return false;
    }

    /**
     * Trata erros de envio
     * Similar ao Chatwoot: external_error e Messages::StatusUpdateService
     * 
     * @param array $errorResponse Resposta de erro da API
     * @param string $messageContent Conteúdo da mensagem (para logs)
     * @return void
     */
    protected function handleError(array $errorResponse, string $messageContent): void
    {
        $errorMessage = $errorResponse['error']['message'] ?? 'Unknown error';
        $errorCode = $errorResponse['error']['code'] ?? null;

        // Erro 190: Access token expired or invalid (similar ao Chatwoot)
        if ($errorCode == 190) {
            Log::warning('[INSTAGRAM SEND] Access token expired or invalid (code 190)', [
                'message_id' => $this->message->id,
                'channel_id' => $this->channel->id,
            ]);
            $this->channel->authorizationError();
        }

        // Formata mensagem de erro (similar ao Chatwoot: external_error)
        $externalError = $errorCode ? "{$errorCode} - {$errorMessage}" : $errorMessage;
        
        Log::error('[INSTAGRAM SEND] Instagram response error', [
            'message_id' => $this->message->id,
            'error' => $externalError,
            'message_content' => $messageContent,
        ]);

        // Atualiza status da mensagem (similar ao Chatwoot: Messages::StatusUpdateService)
        // Trunca mensagem de erro se muito longa
        if (strlen($externalError) > 1000) {
            $externalError = substr($externalError, 0, 997) . '...';
        }
        
        $this->message->update([
            'status' => Message::STATUS_FAILED,
            'external_error' => $externalError,
        ]);
    }
}

