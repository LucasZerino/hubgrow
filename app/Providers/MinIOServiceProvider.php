<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class MinIOServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     * Inicializa o MinIO: cria bucket se não existir e configura políticas públicas
     */
    public function boot(): void
    {
        // Só inicializa se estiver usando S3/MinIO e se o pacote estiver instalado
        if (config('filesystems.default') !== 's3') {
            return;
        }

        // Verifica se o pacote flysystem-aws-s3-v3 está instalado
        if (!class_exists(\League\Flysystem\AwsS3V3\PortableVisibilityConverter::class)) {
            Log::warning('[MINIO] Pacote league/flysystem-aws-s3-v3 não está instalado. MinIO não será inicializado automaticamente.');
            return;
        }

        try {
            $this->initializeMinIO();
        } catch (\Exception $e) {
            // Log do erro mas não quebra a aplicação
            Log::warning('[MINIO] Erro ao inicializar MinIO: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Inicializa o MinIO: cria bucket se não existir
     * Nota: A política pública deve ser configurada manualmente via MinIO Console ou mc CLI
     * mc anonymous set download local/hubphp
     */
    protected function initializeMinIO(): void
    {
        $bucket = config('filesystems.disks.s3.bucket');
        $disk = Storage::disk('s3');

        // Tenta criar um arquivo de teste para verificar se bucket existe
        // Se falhar, tenta criar o bucket
        try {
            // Tenta listar objetos (verifica se bucket existe)
            $disk->files('/');
            Log::info("[MINIO] Bucket já existe: {$bucket}");
        } catch (\Exception $e) {
            // Se falhar, tenta criar o bucket
            Log::info("[MINIO] Tentando criar bucket: {$bucket}");
            $this->createBucket($bucket);
        }

        Log::info("[MINIO] MinIO inicializado. Bucket: {$bucket}");
        Log::info("[MINIO] Para tornar o bucket público, execute: mc anonymous set download local/{$bucket}");
    }

    /**
     * Cria bucket no MinIO usando a API do MinIO
     * Alternativa: usar o Storage facade (mas pode não funcionar se bucket não existir)
     */
    protected function createBucket(string $bucket): void
    {
        try {
            $endpoint = config('filesystems.disks.s3.endpoint');
            $accessKey = config('filesystems.disks.s3.key');
            $secretKey = config('filesystems.disks.s3.secret');

            // MinIO API para criar bucket
            // Nota: MinIO não expõe API pública para criar buckets via HTTP
            // A melhor abordagem é usar o Storage facade que cria automaticamente
            // ou configurar o bucket manualmente no docker-compose
            
            // Tenta criar um arquivo vazio para forçar criação do bucket
            // O MinIO cria o bucket automaticamente quando necessário
            $disk = Storage::disk('s3');
            $testPath = '.minio-init';
            
            try {
                $disk->put($testPath, '');
                $disk->delete($testPath);
                Log::info("[MINIO] Bucket criado/verificado com sucesso: {$bucket}");
            } catch (\Exception $e) {
                Log::warning("[MINIO] Não foi possível criar bucket automaticamente. Crie manualmente no MinIO Console ou via: mc mb local/{$bucket}");
                Log::warning("[MINIO] Erro: {$e->getMessage()}");
            }
        } catch (\Exception $e) {
            Log::error("[MINIO] Erro ao criar bucket: {$e->getMessage()}");
            throw $e;
        }
    }
}

