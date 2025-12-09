<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event MessageCreated
 * 
 * Disparado quando uma mensagem é criada.
 * Faz broadcast via WebSocket para o frontend através do Laravel Reverb.
 * 
 * Usa ShouldBroadcast para enfileirar o broadcast e garantir processamento confiável.
 * A fila garante que os eventos não sejam perdidos mesmo sob carga.
 */
class MessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;

    /**
     * Handle a broadcast failure.
     * 
     * Chamado quando o broadcast falha.
     * 
     * @param \Throwable $exception
     * @return void
     */
    public function broadcastFailed(\Throwable $exception): void
    {
        error_log('[MESSAGE CREATED EVENT] ❌ BROADCAST FALHOU: ' . $exception->getMessage());
        error_log('[MESSAGE CREATED EVENT] Stack trace: ' . $exception->getTraceAsString());
        \Illuminate\Support\Facades\Log::error('[MESSAGE CREATED EVENT] Broadcast failed', [
            'message_id' => $this->message->id,
            'account_id' => $this->message->account_id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Construtor
     * 
     * @param Message $message
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
        
        // Carrega relacionamentos necessários
        $this->message->loadMissing(['sender', 'conversation', 'inbox', 'account']);
    }

    /**
     * Canais para broadcast
     * 
     * Canais privados garantem que apenas usuários com acesso à account recebam eventos.
     * A validação é feita em routes/channels.php
     * 
     * @return array
     */
    public function broadcastOn(): array
    {
        // Canal PÚBLICO da account (sem autenticação)
        $channelName = "account.{$this->message->account_id}";
        
        error_log('[MESSAGE CREATED EVENT] ========== BROADCAST DISPARADO ==========');
        error_log('[MESSAGE CREATED EVENT] Message ID: ' . $this->message->id);
        error_log('[MESSAGE CREATED EVENT] Account ID: ' . $this->message->account_id);
        error_log('[MESSAGE CREATED EVENT] Canal: ' . $channelName);
        error_log('[MESSAGE CREATED EVENT] Evento: message.created');
        error_log('[MESSAGE CREATED EVENT] Broadcast connection: ' . config('broadcasting.default'));
        error_log('[MESSAGE CREATED EVENT] REVERB_HOST: ' . env('REVERB_HOST', 'NOT SET'));
        error_log('[MESSAGE CREATED EVENT] REVERB_PORT: ' . env('REVERB_PORT', 'NOT SET'));
        
        try {
            return [
                new Channel($channelName),
            ];
        } catch (\Exception $e) {
            error_log('[MESSAGE CREATED EVENT] ❌ ERRO ao criar canal: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Nome do evento para broadcast
     * 
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * Fila para processar o broadcast
     * 
     * Usa fila 'critical' (similar ao Chatwoot) para garantir processamento
     * prioritário e confiável. Nada pode ser perdido aqui.
     * 
     * @return string
     */
    public function broadcastQueue(): string
    {
        return 'critical';
    }

    /**
     * Dados para broadcast
     * 
     * @return array
     */
    public function broadcastWith(): array
    {
        // Carrega os relacionamentos necessários se não estiverem carregados
        if (!$this->message->relationLoaded('attachments')) {
            $this->message->load('attachments');
        }
        
        // Carrega o contato se não estiver carregado
        if ($this->message->conversation && !$this->message->conversation->relationLoaded('contact')) {
            $this->message->conversation->load('contact');
        }
        
        $attachments = $this->message->attachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'file_type' => $attachment->file_type,
                'file_url' => $attachment->external_url ?? $attachment->file_url,
                'file_size' => $attachment->file_size,
                'file_name' => $attachment->file_name,
            ];
        })->toArray();
        
        $contactData = null;
        if ($this->message->conversation && $this->message->conversation->contact) {
            $contact = $this->message->conversation->contact;
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
        
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'content' => $this->message->content,
            'content_type' => $this->message->content_type,
            'message_type' => $this->message->message_type,
            'status' => $this->message->status,
            'source_id' => $this->message->source_id,
            'created_at' => $this->message->created_at?->toIso8601String(),
            'updated_at' => $this->message->updated_at?->toIso8601String(),
            'attachments' => $attachments,
            'contact' => $contactData,
            'conversation' => [
                'id' => $this->message->conversation_id,
                'display_id' => $this->message->conversation?->display_id,
                'status' => $this->message->conversation?->status,
            ],
            'inbox' => [
                'id' => $this->message->inbox_id,
                'name' => $this->message->inbox?->name,
            ],
            'account_id' => $this->message->account_id,
            'sender' => $this->message->sender ? [
                'id' => $this->message->sender->id,
                'name' => $this->message->sender->name,
                'email' => $this->message->sender->email,
            ] : null,
        ];
    }
}

