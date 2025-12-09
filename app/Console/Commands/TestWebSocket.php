<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;

class TestWebSocket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websocket:test {--channel=} {--event=} {--data=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa a conexão WebSocket enviando um evento';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $channel = $this->option('channel') ?? 'account.1';
        $event = $this->option('event') ?? 'TestEvent';
        $data = $this->option('data') ? json_decode($this->option('data'), true) : ['message' => 'Teste de WebSocket'];

        $this->info("Enviando evento para o canal: {$channel}");
        $this->info("Evento: {$event}");
        $this->info("Dados: " . json_encode($data));

        try {
            // Dispara um evento de teste
            Broadcast::channel($channel, function ($user) {
                return true; // Permite acesso ao canal para teste
            });

            // Cria um evento simples
            $eventClass = new class($data) {
                public $data;
                
                public function __construct($data) {
                    $this->data = $data;
                }
                
                public function broadcastOn() {
                    return ['account.1'];
                }
                
                public function broadcastAs() {
                    return 'test.event';
                }
            };

            broadcast($eventClass);
            
            $this->info('✅ Evento enviado com sucesso!');
        } catch (\Exception $e) {
            $this->error('❌ Erro ao enviar evento: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }
}