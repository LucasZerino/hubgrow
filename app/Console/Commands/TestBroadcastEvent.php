<?php

namespace App\Console\Commands;

use App\Events\MessageCreated;
use App\Models\Message;
use Illuminate\Console\Command;

class TestBroadcastEvent extends Command
{
    protected $signature = 'test:broadcast-event {--message_id=} {--account_id=1}';
    protected $description = 'Testa o envio de eventos de broadcast (MessageCreated)';

    public function handle()
    {
        $messageId = $this->option('message_id');
        $accountId = (int) $this->option('account_id');

        if ($messageId) {
            // Usa mensagem existente
            $message = Message::withoutGlobalScopes()
                ->where('id', $messageId)
                ->where('account_id', $accountId)
                ->with(['account', 'conversation', 'inbox', 'sender'])
                ->first();

            if (!$message) {
                $this->error("Mensagem {$messageId} não encontrada para account {$accountId}");
                return 1;
            }

            $this->info("Usando mensagem existente: ID {$message->id}");
        } else {
            // Cria mensagem de teste
            $this->info("Criando mensagem de teste para account {$accountId}...");

            // Busca uma conversa existente
            $conversation = \App\Models\Conversation::withoutGlobalScopes()
                ->where('account_id', $accountId)
                ->first();

            if (!$conversation) {
                $this->error("Nenhuma conversa encontrada para account {$accountId}");
                $this->info("Crie uma conversa primeiro ou use --message_id para testar com uma mensagem existente");
                return 1;
            }

            // Cria mensagem de teste
            $message = Message::create([
                'account_id' => $accountId,
                'inbox_id' => $conversation->inbox_id,
                'conversation_id' => $conversation->id,
                'sender_id' => null,
                'message_type' => Message::TYPE_OUTGOING,
                'content' => 'Mensagem de teste para broadcast - ' . now()->format('H:i:s'),
                'content_type' => Message::CONTENT_TYPE_TEXT,
                'status' => Message::STATUS_SENT,
                'private' => [],
            ]);

            $this->info("Mensagem de teste criada: ID {$message->id}");
        }

        // Carrega relacionamentos necessários
        $message->loadMissing(['account', 'conversation', 'inbox', 'sender']);

        $this->info("Disparando evento MessageCreated...");
        $this->line("  - Message ID: {$message->id}");
        $this->line("  - Account ID: {$message->account_id}");
        $this->line("  - Conversation ID: {$message->conversation_id}");
        $this->line("  - Inbox ID: {$message->inbox_id}");

        try {
            MessageCreated::dispatch($message);
            $this->info("✅ Evento MessageCreated disparado com sucesso!");
            $this->line("");
            $this->line("Verifique os logs:");
            $this->line("  docker exec hubphp_backend_dev php artisan logs:instagram --limit=20");
            $this->line("");
            $this->line("Verifique os logs do Reverb:");
            $this->line("  docker logs hubphp_reverb_dev --tail 20");
        } catch (\Exception $e) {
            $this->error("❌ Erro ao disparar evento: " . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}

