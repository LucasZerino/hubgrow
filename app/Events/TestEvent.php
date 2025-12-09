<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;
    public $accountId;

    public function __construct($accountId)
    {
        $this->accountId = $accountId;
        $this->message = 'Teste de WebSocket - ' . now()->format('H:i:s');
        
        \Illuminate\Support\Facades\Log::info('[TEST EVENT] Evento criado', [
            'account_id' => $accountId,
            'message' => $this->message,
        ]);
    }

    public function broadcastOn()
    {
        \Illuminate\Support\Facades\Log::info('[TEST EVENT] broadcastOn chamado', [
            'account_id' => $this->accountId,
            'channel' => "account.{$this->accountId}",
        ]);
        
        return new PrivateChannel("account.{$this->accountId}");
    }

    public function broadcastAs()
    {
        return 'test.event';
    }
}
