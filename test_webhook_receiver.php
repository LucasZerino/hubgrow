<?php
// Servidor simples para receber webhooks de teste
// Rodar com: php -S localhost:8080 test_webhook_receiver.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lê o corpo da requisição
    $input = file_get_contents('php://input');
    
    // Obtém cabeçalhos
    $headers = getallheaders();
    
    // Registra no log
    $logEntry = date('Y-m-d H:i:s') . " - Webhook recebido:\n";
    $logEntry .= "Headers: " . json_encode($headers) . "\n";
    $logEntry .= "Body: " . $input . "\n";
    $logEntry .= str_repeat("-", 50) . "\n";
    
    file_put_contents('webhook_log.txt', $logEntry, FILE_APPEND | LOCK_EX);
    
    // Retorna resposta de sucesso
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'message' => 'Webhook received']);
    
    exit;
}

// Página de status
?>
<!DOCTYPE html>
<html>
<head>
    <title>Webhook Receiver - Test</title>
</head>
<body>
    <h1>Webhook Receiver - Test</h1>
    <p>Servidor rodando e pronto para receber webhooks.</p>
    <p>Endpoint: <code>POST http://localhost:8080</code></p>
    <p>Logs serão salvos em <code>webhook_log.txt</code></p>
    
    <h2>Últimos logs:</h2>
    <pre><?php 
    if (file_exists('webhook_log.txt')) {
        echo htmlspecialchars(file_get_contents('webhook_log.txt'));
    } else {
        echo "Nenhum webhook recebido ainda.";
    }
    ?></pre>
</body>
</html>