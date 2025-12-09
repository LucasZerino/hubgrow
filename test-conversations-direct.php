<?php

/**
 * Script de teste direto para o endpoint de conversations
 * Executa: docker exec hubphp_backend_dev php /var/www/html/test-conversations-direct.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Illuminate\Http\Request::create(
    '/api/v1/accounts/1/conversations?all=true&limit=50',
    'GET',
    [],
    [],
    [],
    [
        'HTTP_Authorization' => 'Bearer 1|Lfq7vTfLXrXnl9JZvkTqjlsebea6mSBSsBJvXbkN54b1a913',
        'HTTP_Accept' => 'application/json',
        'HTTP_Origin' => 'http://localhost:5173',
    ]
);

echo "=== TESTANDO ENDPOINT DE CONVERSATIONS ===\n\n";

try {
    $response = $kernel->handle($request);
    
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content-Length: " . strlen($response->getContent()) . "\n";
    echo "Content-Type: " . $response->headers->get('Content-Type') . "\n";
    echo "\n--- Headers CORS ---\n";
    echo "Access-Control-Allow-Origin: " . $response->headers->get('Access-Control-Allow-Origin') . "\n";
    echo "\n--- Primeiros 500 caracteres da resposta ---\n";
    echo substr($response->getContent(), 0, 500) . "\n";
    
    $kernel->terminate($request, $response);
    
} catch (\Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

