<?php

namespace App\Console\Commands;

use App\Jobs\SendReplyJob;
use App\Models\Message;
use Illuminate\Console\Command;

class TestSendReplyJob extends Command
{
    protected $signature = 'test:send-reply-job {message_id}';
    protected $description = 'Testa o processamento do SendReplyJob para uma mensagem';

    public function handle()
    {
        $messageId = $this->argument('message_id');
        
        $message = Message::withoutGlobalScopes()->find($messageId);
        
        if (!$message) {
            $this->error("Mensagem {$messageId} não encontrada");
            return 1;
        }
        
        $this->info("Mensagem encontrada:");
        $this->line("  - ID: {$message->id}");
        $this->line("  - Type: {$message->message_type} (1=OUTGOING)");
        $this->line("  - Status: {$message->status}");
        $this->line("  - Source ID: " . ($message->source_id ?? 'NULL'));
        $this->line("  - Private: " . json_encode($message->private));
        
        $this->info("\nDisparando SendReplyJob...");
        try {
            SendReplyJob::dispatch($message->id)->onQueue('high');
            $this->info("✅ SendReplyJob disparado com sucesso");
            
            $this->info("\nProcessando job manualmente...");
            $job = new SendReplyJob($message->id);
            $job->handle();
            
            $this->info("✅ Job processado com sucesso");
            
            $message->refresh();
            $this->info("\nMensagem após processamento:");
            $this->line("  - Status: {$message->status}");
            $this->line("  - Source ID: " . ($message->source_id ?? 'NULL'));
            
        } catch (\Exception $e) {
            $this->error("❌ Erro: " . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}

