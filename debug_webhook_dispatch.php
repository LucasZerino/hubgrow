<?php

use App\Models\Message;
use App\Models\Inbox;
use App\Models\Account;
use App\Support\Current;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/vendor/autoload.php';

// Force DB host to localhost for this script
putenv('DB_HOST=127.0.0.1');
putenv('REDIS_HOST=127.0.0.1');
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['REDIS_HOST'] = '127.0.0.1';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Setup
$accountId = 1;
$inboxId = 11; // Facebook inbox
$account = Account::find($accountId);

echo "Simulating Message Create Event...\n";

// Scenario 1: No Account Context
echo "\n--- Scenario 1: No Account Context ---\n";
Current::setAccount(null);
try {
    // Create a dummy message (using raw SQL or bypassing scopes to create, but Observer runs normally)
    // Actually, creating with Eloquent triggers Observer.
    
    // We need to create a message without triggering the global scope check on creation? 
    // No, creation doesn't check scope usually, but the Observer DOES check scope when loading relations.
    
    $message = new Message();
    $message->account_id = $accountId;
    $message->inbox_id = $inboxId;
    $message->conversation_id = 42; // Assuming 42 exists
    $message->message_type = Message::TYPE_INCOMING;
    $message->content = "Test Message No Context " . time();
    $message->save(); // Triggers created event
    
    echo "Message created. Check logs for [MESSAGE MODEL] warnings.\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Scenario 2: With Account Context
echo "\n--- Scenario 2: With Account Context ---\n";
Current::setAccount($account);
try {
    $message = new Message();
    $message->account_id = $accountId;
    $message->inbox_id = $inboxId;
    $message->conversation_id = 42;
    $message->message_type = Message::TYPE_INCOMING;
    $message->content = "Test Message With Context " . time();
    $message->save(); // Triggers created event
    
    echo "Message created. Check logs for [MESSAGE MODEL] success.\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
