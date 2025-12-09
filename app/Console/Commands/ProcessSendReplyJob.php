<?php

namespace App\Console\Commands;

use App\Jobs\SendReplyJob;
use Illuminate\Console\Command;

class ProcessSendReplyJob extends Command
{
    protected $signature = 'job:send-reply {message_id}';
    protected $description = 'Processa SendReplyJob manualmente para uma mensagem';

    public function handle()
    {
        $messageId = $this->argument('message_id');
        
        $this->info("Processando SendReplyJob para mensagem {$messageId}...");
        
        $job = new SendReplyJob((int) $messageId);
        $job->handle();
        
        $this->info("Job processado!");
        
        return 0;
    }
}

