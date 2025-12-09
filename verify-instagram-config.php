<?php

/**
 * Script para verificar configuração do Instagram
 * 
 * Execute: php verify-instagram-config.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🔍 Verificando Configuração do Instagram\n";
echo str_repeat("=", 50) . "\n\n";

// Buscar configuração
$config = \App\Models\AppConfig::where('app_name', 'instagram')->first();

if (!$config) {
    echo "❌ ERRO: Nenhuma configuração encontrada com app_name='instagram'\n";
    echo "\nSolução: Crie uma configuração via SuperAdmin → Configurações de Apps → Instagram\n";
    exit(1);
}

echo "✅ Configuração encontrada\n";
echo "   - ID: {$config->id}\n";
echo "   - Nome: {$config->display_name}\n";
echo "   - Ativo: " . ($config->is_active ? 'SIM ✅' : 'NÃO ❌') . "\n";
echo "   - Descrição: {$config->description}\n\n";

// Verificar credenciais
$credentials = $config->credentials ?? [];

echo "📋 Credenciais:\n";
echo str_repeat("-", 50) . "\n";

$appId = $credentials['app_id'] ?? null;
$appSecret = $credentials['app_secret'] ?? null;

if (!$appId) {
    echo "❌ ERRO: app_id não configurado\n";
} else {
    $appId = trim($appId);
    echo "✅ app_id: {$appId}\n";
    echo "   - Tamanho: " . strlen($appId) . " caracteres\n";
    echo "   - É numérico: " . (is_numeric($appId) ? 'SIM ✅' : 'NÃO ❌') . "\n";
    
    if (!is_numeric($appId)) {
        echo "   ⚠️  AVISO: App ID deve ser apenas números!\n";
    }
    
    // Verificar espaços
    if ($appId !== trim($appId)) {
        echo "   ⚠️  AVISO: App ID contém espaços no início/fim!\n";
    }
    
    echo "\n";
    echo "   📝 IMPORTANTE: Este deve ser o MESMO App ID da aplicação Facebook principal.\n";
    echo "   Para verificar, vá em Facebook Developers → Produtos → Instagram → Ferramentas → Gerar Token de Acesso\n";
    echo "   O client_id na URL gerada deve ser: {$appId}\n";
}

echo "\n";

if (!$appSecret) {
    echo "❌ ERRO: app_secret não configurado\n";
} else {
    $appSecret = trim($appSecret);
    echo "✅ app_secret: " . substr($appSecret, 0, 4) . "..." . substr($appSecret, -4) . "\n";
    echo "   - Tamanho: " . strlen($appSecret) . " caracteres\n";
    
    // Verificar espaços
    if ($appSecret !== trim($appSecret)) {
        echo "   ⚠️  AVISO: App Secret contém espaços no início/fim!\n";
    }
}

echo "\n";

// Verificar se está configurado
$isConfigured = \App\Support\AppConfigHelper::isConfigured('instagram');
echo "🔧 Status da Configuração:\n";
echo str_repeat("-", 50) . "\n";
echo "   - Configurado: " . ($isConfigured ? 'SIM ✅' : 'NÃO ❌') . "\n";

if (!$isConfigured) {
    echo "\n❌ PROBLEMAS ENCONTRADOS:\n";
    
    $required = ['app_id', 'app_secret'];
    foreach ($required as $key) {
        $value = $credentials[$key] ?? null;
        if (empty($value) || trim($value) === '') {
            echo "   - {$key}: NÃO CONFIGURADO ou VAZIO\n";
        }
    }
}

echo "\n";

// Testar URL de autorização (simulação)
if ($appId && is_numeric($appId)) {
    echo "🧪 Teste de URL de Autorização:\n";
    echo str_repeat("-", 50) . "\n";
    
    $scopes = ['instagram_business_basic', 'instagram_business_manage_messages'];
    $redirectUri = 'https://yzo6oogltq.loclx.io/instagram-callback';
    
    $params = [
        'client_id' => $appId,
        'redirect_uri' => $redirectUri,
        'scope' => implode(',', $scopes),
        'response_type' => 'code',
        'state' => 'test_state',
        'enable_fb_login' => '0',
        'force_authentication' => '1',
    ];
    
    $url = 'https://api.instagram.com/oauth/authorize?' . http_build_query($params);
    
    echo "   URL gerada (primeiros 100 caracteres):\n";
    echo "   " . substr($url, 0, 100) . "...\n\n";
    
    echo "   Parâmetros:\n";
    foreach ($params as $key => $value) {
        if ($key === 'state') {
            echo "   - {$key}: {$value}\n";
        } else {
            echo "   - {$key}: " . (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value) . "\n";
        }
    }
}

echo "\n";
echo "📝 Próximos Passos:\n";
echo str_repeat("-", 50) . "\n";
echo "1. Verifique se o App ID no sistema é EXATAMENTE igual ao do Facebook Developers\n";
echo "2. Verifique se a aplicação no Facebook Developers é do tipo 'Empresa'\n";
echo "3. Verifique se o produto 'Instagram' está adicionado (não 'Instagram Basic Display')\n";
echo "4. Verifique se o redirect_uri está configurado no Facebook Developers\n";
echo "5. Se tudo estiver correto, o problema pode ser:\n";
echo "   - Aplicação em modo 'Desenvolvimento' sem usuários de teste\n";
echo "   - App ID não corresponde à aplicação correta\n";
echo "   - Produto Instagram não configurado corretamente\n";

echo "\n";

