<?php

namespace App\Logging;

use App\Models\AppLog;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * Handler DatabaseLogHandler
 * 
 * Handler customizado do Monolog que escreve logs no banco de dados.
 * 
 * @package App\Logging
 */
class DatabaseLogHandler extends AbstractProcessingHandler
{
    /**
     * Escreve o log no banco de dados
     * 
     * @param LogRecord $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        try {
            // Extrai informações do contexto
            $context = $record->context;
            $channel = $this->extractChannel($context, $record->message);
            $accountId = $this->extractAccountId($context);
            $userId = $this->extractUserId($context);

            // Remove campos já extraídos do contexto
            $cleanContext = $context;
            unset($cleanContext['channel'], $cleanContext['account_id'], $cleanContext['user_id']);

            // Cria registro no banco
            // O datetime do Monolog pode ser um DateTimeInterface ou timestamp
            $createdAt = $record->datetime;
            if ($createdAt instanceof \DateTimeInterface) {
                $createdAt = \Carbon\Carbon::instance($createdAt);
            } elseif (is_numeric($createdAt)) {
                $createdAt = \Carbon\Carbon::createFromTimestamp($createdAt);
            } else {
                $createdAt = now();
            }
            
            // Usa insert direto para evitar problemas com timestamps
            // IMPORTANTE: Usa DB::connection() para garantir que está usando a conexão correta
            // mesmo dentro de transações de jobs
            DB::connection()->table('app_logs')->insert([
                'level' => $record->level->getName(),
                'message' => $record->message,
                'context' => !empty($cleanContext) ? json_encode($cleanContext) : null,
                'account_id' => $accountId,
                'user_id' => $userId,
                'channel' => $channel,
                'created_at' => $createdAt,
            ]);
        } catch (\Exception $e) {
            // Em caso de erro ao escrever no banco, não quebra a aplicação
            // Pode logar em arquivo como fallback se necessário
            error_log("Failed to write log to database: " . $e->getMessage());
        }
    }

    /**
     * Extrai o canal do contexto ou da mensagem
     * 
     * @param array $context
     * @param string $message
     * @return string|null
     */
    protected function extractChannel(array $context, string $message): ?string
    {
        // Tenta extrair do contexto
        if (isset($context['channel'])) {
            return $context['channel'];
        }

        // Tenta inferir do prefixo da mensagem
        if (preg_match('/\[([A-Z_]+)\]/', $message, $matches)) {
            $prefix = strtolower($matches[1]);
            
            // Mapeia prefixos para canais
            $channelMap = [
                'instagram_webhook' => 'instagram',
                'instagram_incoming' => 'instagram',
                'instagram_oauth_callback' => 'instagram',
                'instagram_channel_creation' => 'instagram',
                'message_model' => 'message',
                'message_created_event' => 'message',
                'webhook_dispatcher' => 'webhook',
                'webhook_job' => 'webhook',
                'webhook_trigger' => 'webhook',
            ];

            return $channelMap[$prefix] ?? null;
        }

        return null;
    }

    /**
     * Extrai account_id do contexto
     * 
     * @param array $context
     * @return int|null
     */
    protected function extractAccountId(array $context): ?int
    {
        return $context['account_id'] ?? null;
    }

    /**
     * Extrai user_id do contexto
     * 
     * @param array $context
     * @return int|null
     */
    protected function extractUserId(array $context): ?int
    {
        return $context['user_id'] ?? null;
    }
}

