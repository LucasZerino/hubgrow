<?php

namespace App\Services\Base;

use App\Models\Message;
use App\Support\Redis\RedisKeys;
use Illuminate\Support\Facades\Redis;

/**
 * Trait IdempotencyTrait
 * 
 * Fornece métodos para garantir idempotência no processamento de mensagens.
 * Aplica o princípio de Single Responsibility (SOLID).
 * 
 * @package App\Services\Base
 */
trait IdempotencyTrait
{
    /**
     * Verifica se a mensagem já foi processada
     * 
     * @param string $sourceId ID único da mensagem na plataforma
     * @return bool
     */
    protected function isMessageProcessed(string $sourceId): bool
    {
        return Message::findBySourceId($sourceId) !== null;
    }

    /**
     * Verifica se a mensagem está em processamento
     * 
     * @param string $sourceId ID único da mensagem na plataforma
     * @return bool
     */
    protected function isMessageUnderProcess(string $sourceId): bool
    {
        $key = sprintf(RedisKeys::MESSAGE_SOURCE_KEY, $sourceId);
        return Redis::exists($key) > 0;
    }

    /**
     * Marca mensagem como em processamento
     * 
     * @param string $sourceId ID único da mensagem na plataforma
     * @param int $ttl Tempo de vida em segundos (padrão: 60)
     * @return void
     */
    protected function markMessageAsProcessing(string $sourceId, int $ttl = 60): void
    {
        $key = sprintf(RedisKeys::MESSAGE_SOURCE_KEY, $sourceId);
        Redis::setex($key, $ttl, true);
    }

    /**
     * Remove marca de processamento da mensagem
     * 
     * @param string $sourceId ID único da mensagem na plataforma
     * @return void
     */
    protected function clearMessageProcessing(string $sourceId): void
    {
        $key = sprintf(RedisKeys::MESSAGE_SOURCE_KEY, $sourceId);
        Redis::del($key);
    }
}

