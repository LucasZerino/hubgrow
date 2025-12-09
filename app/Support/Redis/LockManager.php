<?php

namespace App\Support\Redis;

use Illuminate\Support\Facades\Redis;
use Predis\Response\Status;

/**
 * Redis Lock Manager for distributed locking
 * Similar to Chatwoot's Redis::LockManager
 * Ensures only one instance of an operation runs at a time across all processes/nodes
 */
class LockManager
{
    /**
     * Default lock timeout in seconds
     * If lock isn't released within this time, it will automatically expire
     * Aumentado para 5 segundos para dar tempo suficiente ao processamento
     */
    public const LOCK_TIMEOUT = 5;

    /**
     * Attempts to acquire a lock for the given key
     *
     * @param string $key The key for which the lock is to be acquired
     * @param int $timeout Duration in seconds for which the lock is valid
     * @return bool true if lock was successfully acquired, false otherwise
     */
    public function lock(string $key, int $timeout = self::LOCK_TIMEOUT): bool
    {
        $value = (string) microtime(true);
        
        // SET key value NX EX timeout
        // NX: set only if key does not exist
        // EX: set expiry time in seconds
        // Laravel Redis facade abstrai a diferença entre phpredis e predis
        try {
            // Usa set com opções NX e EX
            // Laravel Redis facade com predis aceita: set($key, $value, 'EX', $timeout, 'NX')
            // Predis retorna um objeto Predis\Response\Status com valor 'OK' quando sucesso
            // ou null quando a chave já existe (NX falhou)
            $result = Redis::set($key, $value, 'EX', $timeout, 'NX');
            
            // Predis retorna um objeto Status que pode ser convertido para string
            // ou pode ser comparado diretamente. Vamos verificar de forma mais robusta
            $acquired = false;
            
            if ($result instanceof Status) {
                $acquired = (string) $result === 'OK';
            } elseif (is_string($result)) {
                $acquired = $result === 'OK';
            } elseif (is_bool($result)) {
                $acquired = $result === true;
            } elseif (is_numeric($result)) {
                $acquired = $result == 1;
            }
            
            \Illuminate\Support\Facades\Log::debug('[LOCK MANAGER] Tentativa de lock', [
                'key' => $key,
                'result' => $result,
                'result_type' => gettype($result),
                'result_class' => is_object($result) ? get_class($result) : null,
                'result_string' => is_object($result) ? (string) $result : null,
                'acquired' => $acquired,
            ]);
            
            return $acquired;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[LOCK MANAGER] Erro ao adquirir lock', [
                'key' => $key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Releases a lock for the given key
     *
     * @param string $key The key for which the lock is to be released
     * @return bool true indicating the lock release operation was initiated
     */
    public function unlock(string $key): bool
    {
        Redis::del($key);
        return true;
    }

    /**
     * Checks if the given key is currently locked
     *
     * @param string $key The key to check
     * @return bool true if the key is locked, false otherwise
     */
    public function locked(string $key): bool
    {
        return Redis::exists($key) > 0;
    }
}

