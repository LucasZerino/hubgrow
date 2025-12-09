<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AppConfig;
use App\Support\AppConfigHelper;

echo "=== DEBUG APP CONFIG ===\n\n";

// 1. Listar todas as configurações
echo "1. Todas as configurações:\n";
$allConfigs = AppConfig::all();
foreach ($allConfigs as $config) {
    echo "   - ID: {$config->id}\n";
    echo "     app_name: {$config->app_name}\n";
    echo "     display_name: {$config->display_name}\n";
    echo "     is_active: " . ($config->is_active ? 'true' : 'false') . "\n";
    echo "     credentials: " . json_encode($config->credentials, JSON_PRETTY_PRINT) . "\n";
    echo "\n";
}

// 2. Buscar especificamente Instagram
echo "2. Buscar Instagram:\n";
$instagramConfig = AppConfig::where('app_name', 'instagram')->first();
if ($instagramConfig) {
    echo "   ✅ Encontrado!\n";
    echo "   - ID: {$instagramConfig->id}\n";
    echo "   - is_active: " . ($instagramConfig->is_active ? 'true' : 'false') . "\n";
    echo "   - credentials: " . json_encode($instagramConfig->credentials, JSON_PRETTY_PRINT) . "\n";
    
    // Verificar credenciais obrigatórias
    echo "\n3. Verificar credenciais obrigatórias:\n";
    $required = AppConfig::getRequiredCredentials('instagram');
    echo "   Required: " . implode(', ', $required) . "\n";
    
    foreach ($required as $key) {
        $value = $instagramConfig->getCredential($key);
        $status = !empty($value) ? '✅' : '❌';
        echo "   {$status} {$key}: " . (!empty($value) ? substr($value, 0, 20) . '...' : 'VAZIO') . "\n";
    }
    
    // Testar isConfigured
    echo "\n4. Testar isConfigured:\n";
    $isConfigured = AppConfig::isConfigured('instagram');
    echo "   Resultado: " . ($isConfigured ? '✅ CONFIGURADO' : '❌ NÃO CONFIGURADO') . "\n";
    
    // Testar helper
    echo "\n5. Testar AppConfigHelper:\n";
    $helperIsConfigured = AppConfigHelper::isConfigured('instagram');
    echo "   Resultado: " . ($helperIsConfigured ? '✅ CONFIGURADO' : '❌ NÃO CONFIGURADO') . "\n";
    
    $appId = AppConfigHelper::get('instagram', 'app_id');
    $appSecret = AppConfigHelper::get('instagram', 'app_secret');
    echo "   app_id: " . ($appId ? substr($appId, 0, 20) . '...' : 'NULL') . "\n";
    echo "   app_secret: " . ($appSecret ? substr($appSecret, 0, 20) . '...' : 'NULL') . "\n";
} else {
    echo "   ❌ NÃO ENCONTRADO!\n";
    echo "   Verifique se o app_name está exatamente como 'instagram'\n";
}

echo "\n=== FIM DEBUG ===\n";

