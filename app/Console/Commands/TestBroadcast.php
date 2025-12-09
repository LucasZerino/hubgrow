<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestBroadcast extends Command
{
    protected $signature = 'test:broadcast {account_id=1}';
    protected $description = 'Testa o broadcast de um evento para uma account';

    public function handle()
    {
        $accountId = $this->argument('account_id');
        
        $this->info("Enviando evento de teste para account.{$accountId}...");
        
        // Dispara um evento de teste (sem fila para debug)
        event(new \App\Events\TestEvent($accountId));
        
        $this->info("Evento enviado com sucesso!");
        
        Log::info('[TEST BROADCAST] Evento de teste enviado', [
            'account_id' => $accountId,
        ]);
    }
}
