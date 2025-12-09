<?php

namespace App\Jobs;

use App\Exceptions\LockAcquisitionException;
use App\Support\Redis\LockManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Base class for jobs that require distributed locking
 * Similar to Chatwoot's MutexApplicationJob
 * Prevents concurrent execution of the same operation
 */
abstract class MutexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?LockManager $lockManager = null;

    /**
     * Obtém instância do LockManager (lazy initialization)
     * 
     * @return LockManager
     */
    protected function getLockManager(): LockManager
    {
        if ($this->lockManager === null) {
            $this->lockManager = new LockManager();
        }
        return $this->lockManager;
    }

    public function __construct()
    {
        // Inicialização opcional no construtor (para uso direto, não via queue)
        $this->lockManager = new LockManager();
    }

    /**
     * Execute the job with a distributed lock
     *
     * @param string $lockKey The key for the lock
     * @param int|null $timeout Lock timeout in seconds (default: LockManager::LOCK_TIMEOUT)
     * @param callable $callback The code to execute within the lock
     * @throws LockAcquisitionException
     */
    protected function withLock(string $lockKey, callable $callback, ?int $timeout = null): void
    {
        $attempts = $this->attempts() + 1;
        $timeout = $timeout ?? LockManager::LOCK_TIMEOUT;
        $lockManager = $this->getLockManager();

        try {
            if ($lockManager->lock($lockKey, $timeout)) {
                Log::info(sprintf(
                    '[%s] Acquired lock for: %s on attempt %d',
                    static::class,
                    $lockKey,
                    $attempts
                ));

                $callback();

                $lockManager->unlock($lockKey);
            } else {
                $this->handleFailedLockAcquisition($lockKey, $attempts);
            }
        } catch (\Exception $e) {
            // Only unlock if it's not a lock acquisition error
            if (!$e instanceof LockAcquisitionException) {
                $lockManager->unlock($lockKey);
            }
            throw $e;
        }
    }

    /**
     * Handle failed lock acquisition
     *
     * @param string $lockKey
     * @param int $attempts
     * @throws LockAcquisitionException
     */
    protected function handleFailedLockAcquisition(string $lockKey, int $attempts): void
    {
        Log::warning(sprintf(
            '[%s] Failed to acquire lock on attempt %d: %s',
            static::class,
            $attempts,
            $lockKey
        ));

        throw new LockAcquisitionException("Failed to acquire lock for key: {$lockKey}");
    }

    /**
     * Handle a job failure.
     * 
     * Método base para tratamento de falhas.
     * Jobs filhos podem sobrescrever para adicionar lógica específica.
     * 
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error(sprintf(
            '[%s] Job falhou após todas as tentativas',
            static::class
        ), [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

