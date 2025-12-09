<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Simula uma requisição GET para a rota
$request = Illuminate\Http\Request::create('/api/v1/accounts/1/inboxes/3', 'GET', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer 20|V356ZIR8KBnwQVZycxKv9WNhnuCBUSh2eblxiW695eeff743',
    'HTTP_ACCEPT' => 'application/json',
]);

// Debug: verifica se a rota existe
echo "Verificando se a rota existe...\n";
try {
    $routes = Illuminate\Support\Facades\Route::getRoutes();
    $matched = $routes->match($request);
    echo "Rota encontrada: " . $matched->getName() . "\n";
    echo "Action: " . $matched->getActionName() . "\n";
} catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
    echo "Rota NÃO encontrada pelo router!\n";
    echo "Tentando encontrar manualmente...\n";
    foreach ($routes->getRoutes() as $route) {
        if ($route->getName() === 'inboxes.show') {
            echo "Rota inboxes.show encontrada manualmente!\n";
            echo "URI: " . $route->uri() . "\n";
            echo "Methods: " . implode(', ', $route->methods()) . "\n";
        }
    }
} catch (\Exception $e) {
    echo "Erro ao verificar rota: " . $e->getMessage() . "\n";
}
echo "\n";

echo "Testando rota: GET /api/v1/accounts/1/inboxes/3\n";
echo "==========================================\n\n";

try {
    $response = $kernel->handle($request);
    
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Response:\n";
    echo $response->getContent() . "\n";
    
    $kernel->terminate($request, $response);
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

