<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Channel\InstagramChannel;
use App\Models\Channel\FacebookChannel;
use App\Services\Instagram\SendOnInstagramService;
use App\Services\Facebook\SendOnFacebookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job SendReplyJob
 * 
 * Envia mensagens para canais externos (Instagram, WhatsApp, etc) após criação.
 * Similar ao SendReplyJob do Chatwoot.
 * 
 * @package App\Jobs
 */
class SendReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de tentativas em caso de falha
     */
    public $tries = 3;

    /**
     * Tempo de espera entre tentativas (em segundos)
     */
    public $backoff = [5, 15, 30];

    /**
     * ID da mensagem a ser enviada
     * 
     * @var int
     */
    protected int $messageId;

    /**
     * Construtor
     * 
     * @param int $messageId
     */
    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     * 
     * @return void
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        error_log('[SEND REPLY JOB] ====== HANDLE CHAMADO ======');
        error_log('[SEND REPLY JOB] Message ID: ' . $this->messageId);
        error_log('[SEND REPLY JOB] Timestamp: ' . now()->toIso8601String());
        
        Log::info('[SEND REPLY JOB] ====== HANDLE CHAMADO ======', [
            'channel' => 'instagram',
            'message_id' => $this->messageId,
        ]);
        
        // 1. Busca apenas a mensagem e a conta primeiro (sem outras relações que dependam de escopo)
        $message = Message::withoutGlobalScopes()
            ->with(['account'])
            ->find($this->messageId);
        
        if (!$message) {
            Log::warning('[SEND REPLY JOB] Message not found', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
            ]);
            return;
        }
        
        // 2. Define a account no contexto ANTES de carregar outras relações
        // Isso é crucial porque o HasAccountScope depende de Current::account()
        // Se carregarmos as relações no with() inicial, o scope falhará (retornando null)
        if ($message->account) {
            \App\Support\Current::setAccount($message->account);
            Log::info('[SEND REPLY JOB] Account definida no contexto', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'account_id' => $message->account_id,
            ]);
        } else {
            Log::error('[SEND REPLY JOB] Message has no account!', [
                'message_id' => $this->messageId,
            ]);
        }

        // 3. Agora carrega as relações dependentes de escopo
        $message->load(['conversation.inbox.channel']);


        // Verifica se é mensagem de saída
        if ($message->message_type !== Message::TYPE_OUTGOING) {
            Log::info('[SEND REPLY JOB] Message is not outgoing, skipping', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'message_type' => $message->message_type,
            ]);
            return;
        }

        // Verifica se já foi enviada (evita loops)
        if ($message->source_id) {
            error_log('[SEND REPLY JOB] ⚠️ Mensagem já tem source_id, pulando envio');
            error_log('[SEND REPLY JOB] Message ID: ' . $this->messageId);
            error_log('[SEND REPLY JOB] Source ID: ' . $message->source_id);
            error_log('[SEND REPLY JOB] Status atual: ' . $message->status);
            Log::info('[SEND REPLY JOB] Message already has source_id, skipping', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'source_id' => $message->source_id,
            ]);
            return;
        }

        // Verifica se é mensagem privada
        // O campo private é um array (cast), então verifica se está vazio
        $isPrivate = !empty($message->private);
        if ($isPrivate) {
            Log::info('[SEND REPLY JOB] Message is private, skipping', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'private_value' => $message->private,
            ]);
            return;
        }

        // Os relacionamentos já foram carregados com eager loading
        // Mas se não estiverem carregados, busca sem global scope
        $conversation = $message->conversation;
        if (!$conversation && $message->conversation_id) {
            $conversation = \App\Models\Conversation::withoutGlobalScopes()
                ->with(['inbox.channel'])
                ->find($message->conversation_id);
        }
        
        if (!$conversation) {
            Log::warning('[SEND REPLY JOB] Conversation not found', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'conversation_id' => $message->conversation_id,
            ]);
            return;
        }

        $inbox = $conversation->inbox;
        if (!$inbox) {
            Log::warning('[SEND REPLY JOB] Inbox not found', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'inbox_id' => $conversation->inbox_id,
            ]);
            return;
        }

        $channel = $inbox->channel;
        if (!$channel) {
            Log::warning('[SEND REPLY JOB] Channel not found', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'inbox_id' => $inbox->id,
            ]);
            return;
        }

        // Identifica o tipo de canal e chama o serviço apropriado
        if ($channel instanceof InstagramChannel) {
            $sendStartTime = microtime(true);
            error_log('[SEND REPLY JOB] Enviando mensagem via Instagram...');
            Log::info('[SEND REPLY JOB] Enviando mensagem via Instagram', [
                'message_id' => $this->messageId,
                'channel_id' => $channel->id,
                'channel_type' => get_class($channel),
            ]);
            $this->sendOnInstagram($message, $channel);
            $sendElapsed = round((microtime(true) - $sendStartTime) * 1000, 2);
            error_log('[SEND REPLY JOB] ✅ Envio concluído em ' . $sendElapsed . 'ms');
        } elseif ($channel instanceof FacebookChannel) {
            $sendStartTime = microtime(true);
            error_log('[SEND REPLY JOB] Enviando mensagem via Facebook...');
            Log::info('[SEND REPLY JOB] Enviando mensagem via Facebook', [
                'message_id' => $this->messageId,
                'channel_id' => $channel->id,
                'channel_type' => get_class($channel),
            ]);
            $this->sendOnFacebook($message, $channel);
            $sendElapsed = round((microtime(true) - $sendStartTime) * 1000, 2);
            error_log('[SEND REPLY JOB] ✅ Envio concluído em ' . $sendElapsed . 'ms');
        } else {
            Log::info('[SEND REPLY JOB] Channel type not supported for sending', [
                'channel' => 'instagram',
                'message_id' => $this->messageId,
                'channel_type' => get_class($channel),
            ]);
        }
        
        // Log de tempo total de processamento
        $totalElapsed = round((microtime(true) - $startTime) * 1000, 2);
        error_log('[SEND REPLY JOB] ====== JOB CONCLUÍDO ======');
        error_log('[SEND REPLY JOB] Tempo total: ' . $totalElapsed . 'ms');
        error_log('[SEND REPLY JOB] Message ID: ' . $this->messageId);
        
        Log::info('[SEND REPLY JOB] Job concluído', [
            'channel' => 'instagram',
            'message_id' => $this->messageId,
            'total_elapsed_ms' => $totalElapsed,
        ]);
    }

    /**
     * Envia mensagem via Instagram
     * 
     * @param Message $message
     * @param InstagramChannel $channel
     * @return void
     */
    protected function sendOnInstagram(Message $message, InstagramChannel $channel): void
    {
        try {
            Log::info('[SEND REPLY JOB] Sending message via Instagram', [
                'channel' => 'instagram',
                'message_id' => $message->id,
                'channel_id' => $channel->id,
            ]);

            $service = new SendOnInstagramService($message);
            $service->perform();

            Log::info('[SEND REPLY JOB] Message sent successfully', [
                'channel' => 'instagram',
                'message_id' => $message->id,
            ]);
        } catch (\Exception $e) {
            Log::error('[SEND REPLY JOB] Failed to send message', [
                'channel' => 'instagram',
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Atualiza status da mensagem para falha
            // Trunca mensagem de erro se muito longa (fallback caso migration não tenha rodado)
            $errorMessage = $e->getMessage();
            if (strlen($errorMessage) > 1000) {
                $errorMessage = substr($errorMessage, 0, 997) . '...';
            }
            
            $message->update([
                'status' => Message::STATUS_FAILED,
                'external_error' => $errorMessage,
            ]);

            throw $e;
        }
    }

    /**
     * Envia mensagem via Facebook
     * 
     * @param Message $message
     * @param FacebookChannel $channel
     * @return void
     */
    protected function sendOnFacebook(Message $message, FacebookChannel $channel): void
    {
        Log::info('[SEND REPLY JOB] Iniciando sendOnFacebook', [
            'message_id' => $message->id,
            'channel_id' => $channel->id
        ]);

        try {
            Log::info('[SEND REPLY JOB] Sending message via Facebook', [
                'channel' => 'facebook',
                'message_id' => $message->id,
                'channel_id' => $channel->id,
            ]);

            $service = new SendOnFacebookService($channel);
            
            Log::info('[SEND REPLY JOB] Obtendo recipientId', ['message_id' => $message->id]);
            $recipientId = $this->getFacebookRecipientId($message);
            Log::info('[SEND REPLY JOB] RecipientId obtido', [
                'message_id' => $message->id,
                'recipient_id' => $recipientId
            ]);

            // Envia anexos se houver
            if ($message->attachments && $message->attachments->isNotEmpty()) {
                Log::info('[SEND REPLY JOB] Enviando attachments', ['count' => $message->attachments->count()]);
                foreach ($message->attachments as $attachment) {
                    // Mapeia tipo de arquivo interno para tipo do Facebook
                    $type = match($attachment->file_type) {
                        'image' => 'image',
                        'audio' => 'audio',
                        'video' => 'video',
                        default => 'file',
                    };
                    
                    // Usa URL externa se disponível, senão usa file_url
                    // O Facebook precisa de URL pública acessível
                    // Usa downloadUrl() para garantir que seja usada a URL pública (proxy backend ou MinIO público)
                    $url = $attachment->downloadUrl();
                    
                    if (empty($url)) {
                        Log::warning('[SEND REPLY JOB] Attachment URL is empty', ['attachment_id' => $attachment->id]);
                        continue;
                    }
                    
                    Log::info('[SEND REPLY JOB] Sending attachment via Facebook', [
                        'attachment_id' => $attachment->id,
                        'type' => $type,
                        'url' => $url,
                    ]);
                    
                    $response = $service->sendAttachment($recipientId, $url, $type);

                    if (isset($response['message_id'])) {
                        // Se não tiver conteúdo de texto, atualiza o status aqui
                        // Se tiver texto, o status será atualizado após o envio do texto
                        if (!$message->content) {
                            $message->update([
                                'source_id' => $response['message_id'],
                                'status' => Message::STATUS_SENT,
                                'external_error' => null, // Limpa erro anterior em caso de sucesso no retry
                            ]);
                            
                            Log::info('[SEND REPLY JOB] Facebook attachment sent successfully', [
                                'message_id' => $message->id,
                                'source_id' => $response['message_id'],
                            ]);
                        }
                    }
                }
            }

            // Envia texto se houver
            if ($message->content) {
                Log::info('[SEND REPLY JOB] Sending text via Facebook', [
                    'content_length' => strlen($message->content),
                ]);
                
                $response = $service->sendText($recipientId, $message->content);
                
                Log::info('[SEND REPLY JOB] Resposta do Facebook', [
                    'message_id' => $message->id,
                    'response' => $response
                ]);

                if (isset($response['message_id'])) {
                    $message->update([
                        'source_id' => $response['message_id'],
                        'status' => Message::STATUS_SENT,
                    ]);
                    
                    Log::info('[SEND REPLY JOB] Facebook message sent successfully', [
                        'message_id' => $message->id,
                        'source_id' => $response['message_id'],
                    ]);
                } else {
                    Log::warning('[SEND REPLY JOB] Resposta do Facebook sem message_id', [
                        'message_id' => $message->id,
                        'response' => $response
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            error_log('[SEND REPLY JOB] ❌ ERRO ao enviar via Facebook: ' . $e->getMessage());
            Log::error('[SEND REPLY JOB] Failed to send message via Facebook', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorMessage = $e->getMessage();
            if (strlen($errorMessage) > 1000) {
                $errorMessage = substr($errorMessage, 0, 997) . '...';
            }
            
            $message->update([
                'status' => Message::STATUS_FAILED,
                'external_error' => $errorMessage,
            ]);

            throw $e;
        }
    }

    /**
     * Obtém ID do destinatário no Facebook
     * 
     * @param Message $message
     * @return string
     */
    protected function getFacebookRecipientId(Message $message): string
    {
        $conversation = $message->conversation;
        
        Log::info('[SEND REPLY JOB] Buscando recipientId', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'inbox_id' => $conversation->inbox_id
        ]);

        if (!$conversation->contact_id) {
            Log::error('[SEND REPLY JOB] Contact not found for conversation', ['conversation_id' => $conversation->id]);
            throw new \RuntimeException('Contact not found for conversation');
        }

        $contactInbox = \App\Models\ContactInbox::where('contact_id', $conversation->contact_id)
            ->where('inbox_id', $conversation->inbox_id)
            ->first();

        if (!$contactInbox) {
            Log::error('[SEND REPLY JOB] ContactInbox not found', [
                'contact_id' => $conversation->contact_id,
                'inbox_id' => $conversation->inbox_id
            ]);
            throw new \RuntimeException('ContactInbox not found for conversation');
        }
        
        Log::info('[SEND REPLY JOB] ContactInbox encontrado', [
            'contact_inbox_id' => $contactInbox->id,
            'source_id' => $contactInbox->source_id
        ]);
        
        return $contactInbox->source_id;
    }
}

