<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID', env('MINIO_ROOT_USER', 'minioadmin')),
            'secret' => env('AWS_SECRET_ACCESS_KEY', env('MINIO_ROOT_PASSWORD', 'minioadmin')),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET', env('MINIO_BUCKET', 'hubphp')),
            'url' => env('AWS_URL', env('MINIO_URL', 'http://localhost:9000')),
            // Endpoint: usa 'minio' se estiver no Docker, 'localhost' se rodando localmente
            // Adiciona verificação para Windows/Localhost para corrigir erro de resolução de DNS 'minio'
            'endpoint' => (function() {
                $endpoint = env('AWS_ENDPOINT', env('MINIO_ENDPOINT'));
                
                // Se não estiver definido, usa a lógica padrão
                if (!$endpoint) {
                    return (getenv('APP_ENV') === 'local' && file_exists('/.dockerenv')) 
                        ? 'http://minio:9000' 
                        : 'http://localhost:9000';
                }
                
                // Se estiver definido como 'minio' mas estamos rodando no Windows (fora do Docker),
                // força localhost. Isso corrige ambientes de dev onde .env tem 'minio' mas roda via 'php artisan serve'
                if (str_contains($endpoint, '//minio:') && stripos(PHP_OS, 'WIN') === 0) {
                    return str_replace('//minio:', '//localhost:', $endpoint);
                }
                
                return $endpoint;
            })(),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', env('MINIO_USE_PATH_STYLE', true)),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
