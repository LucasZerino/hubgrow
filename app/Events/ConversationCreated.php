<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event ConversationCreated
 * 
 * Disparado quando uma conversa é criada.
 * Faz broadcast via WebSocket para o frontend através do Laravel Reverb.
 */
class ConversationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Conversation $conversation;

    /**
     * Construtor
     * 
     * @param Conversation $conversation
     */
    public function __construct(Conversation $conversation)
    {
        $this->conversation = $conversation;
        
        // Carrega relacionamentos necessários
        $this->conversation->loadMissing(['inbox', 'contact', 'account']);
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
        // Canal privado da account (apenas usuários autorizados recebem)
        return [
            new PrivateChannel("account.{$this->conversation->account_id}"),
        ];
    }

    /**
     * Nome do evento para broadcast
     * 
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'conversation.created';
    }

    /**
     * Dados para broadcast
     * 
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'display_id' => $this->conversation->display_id,
            'status' => $this->conversation->status,
            'priority' => $this->conversation->priority,
            'assignee_id' => $this->conversation->assignee_id,
            'last_activity_at' => $this->conversation->last_activity_at?->toIso8601String(),
            'created_at' => $this->conversation->created_at?->toIso8601String(),
            'inbox' => [
                'id' => $this->conversation->inbox_id,
                'name' => $this->conversation->inbox?->name,
            ],
            'contact' => [
                'id' => $this->conversation->contact_id,
                'name' => $this->conversation->contact?->name,
            ],
            'account_id' => $this->conversation->account_id,
        ];
    }
}

