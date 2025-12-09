<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ContactInbox;
use Illuminate\Support\Facades\Log;
use App\Support\Current;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force DB connection to localhost if needed (copying from previous successful debug scripts)
$config = config('database.connections.pgsql');
$config['host'] = '127.0.0.1';
$config['port'] = '5432';
config(['database.connections.pgsql' => $config]);
config(['database.connections.mysql' => array_merge(config('database.connections.mysql'), ['host' => '127.0.0.1'])]);
config(['database.redis.default' => array_merge(config('database.redis.default'), ['host' => '127.0.0.1'])]);

echo "Analyzing Conversation 42...\n";

// 1. Load Conversation without scopes
$conversation = Conversation::withoutGlobalScopes()->find(42);

if (!$conversation) {
    die("Conversation 42 not found.\n");
}

echo "Conversation found. Account ID: " . $conversation->account_id . "\n";
echo "Contact ID: " . $conversation->contact_id . "\n";
echo "Inbox ID: " . $conversation->inbox_id . "\n";

// 2. Set Account Context
$account = \App\Models\Account::find($conversation->account_id);
if ($account) {
    Current::setAccount($account);
    echo "Account context set to ID: " . $account->id . "\n";
} else {
    echo "Account not found!\n";
}

// 3. Check Relations with Context
echo "\nChecking relations WITH account context:\n";
$conversation->load(['contact', 'inbox']);

if ($conversation->contact) {
    echo "Contact loaded successfully via relation. ID: " . $conversation->contact->id . "\n";
} else {
    echo "Contact NOT loaded via relation.\n";
}

if ($conversation->inbox) {
    echo "Inbox loaded successfully via relation. ID: " . $conversation->inbox->id . "\n";
} else {
    echo "Inbox NOT loaded via relation.\n";
}

// 4. Check ContactInbox (Crucial for Facebook)
echo "\nChecking ContactInbox for Facebook delivery...\n";
$contactInbox = ContactInbox::where('contact_id', $conversation->contact_id)
    ->where('inbox_id', $conversation->inbox_id)
    ->first();

if ($contactInbox) {
    echo "ContactInbox found. ID: " . $contactInbox->id . "\n";
    echo "Source ID (Recipient ID): " . ($contactInbox->source_id ?? 'NULL') . "\n";
} else {
    echo "ContactInbox NOT found! This will cause delivery failure.\n";
}

// 5. Simulate Incoming Message Creation to test Webhook Trigger
echo "\nSimulating Incoming Message Creation...\n";

try {
    DB::beginTransaction();
    
    // Mock message data
    $message = new Message();
    $message->account_id = $conversation->account_id;
    $message->inbox_id = $conversation->inbox_id;
    $message->conversation_id = $conversation->id;
    $message->message_type = Message::TYPE_INCOMING;
    $message->content = "Debug incoming message " . time();
    $message->sender_type = get_class($conversation->contact); // Assuming contact exists
    $message->sender_id = $conversation->contact_id;
    $message->status = Message::STATUS_SENT;
    
    // We use save() to trigger events
    $message->save();
    
    echo "Message created with ID: " . $message->id . "\n";
    echo "Check laravel.log for [MESSAGE MODEL] logs.\n";
    
    DB::rollBack(); // Don't persist junk
    echo "Transaction rolled back.\n";
    
} catch (\Exception $e) {
    echo "Error creating message: " . $e->getMessage() . "\n";
    DB::rollBack();
}
