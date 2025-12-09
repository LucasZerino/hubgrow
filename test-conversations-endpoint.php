<?php
/**
 * Script de teste para verificar o endpoint de conversations
 * Execute: php test-conversations-endpoint.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Support\Current;

// Simula autenticação
$user = User::first();
if (!$user) {
    die("Erro: Nenhum usuário encontrado no banco de dados\n");
}

// Simula contexto
Current::setUser($user);
Current::setAccount($user->accounts()->first());

echo "Testando endpoint de conversations...\n";
echo "User ID: {$user->id}\n";
echo "Account ID: " . Current::account()->id . "\n\n";

$startTime = microtime(true);

try {
    $request = Illuminate\Http\Request::create(
        '/api/v1/accounts/1/conversations?all=true&limit=50',
        'GET'
    );
    
    $request->setUserResolver(function () use ($user) {
        return $user;
    });
    
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "Status Code: {$response->getStatusCode()}\n";
    echo "Duration: {$duration}ms\n";
    echo "Content Length: " . strlen($response->getContent()) . " bytes\n";
    
    $data = json_decode($response->getContent(), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "JSON válido\n";
        echo "Items count: " . (is_array($data) ? count($data) : 'N/A') . "\n";
    } else {
        echo "ERRO: JSON inválido - " . json_last_error_msg() . "\n";
    }
    
    $kernel->terminate($request, $response);
    
} catch (\Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

