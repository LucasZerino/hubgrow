<?php
// Script para enviar um webhook de teste
require_once __DIR__ . '/vendor/autoload.php';

// Carrega o Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\Webhooks\WebhookJob;

// Dados de teste
$webhookUrl = 'http://host.docker.internal:8080'; // URL do nosso receptor de testes
$payload = [
    'event' => 'instagram.connected',
    'channel' => [
        'id' => 1,
        'instagram_id' => '123456789',
        'username' => 'test_user',
        'webhook_url' => 'http://host.docker.internal:8080',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ],
    'inbox' => [
        'id' => 1,
        'name' => 'Test Instagram Inbox',
        'is_active' => true,
    ],
    'account' => [
        'id' => 1,
    ],
];

echo "Enviando webhook de teste...\n";
echo "URL: " . $webhookUrl . "\n";
echo "Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

// Envia o webhook
WebhookJob::dispatchSync($webhookUrl, $payload, 'instagram_connection');

echo "Webhook enviado com sucesso!\n";