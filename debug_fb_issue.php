<?php

use App\Models\Conversation;
use App\Models\ContactInbox;
use App\Models\Message;
use App\Models\Contact;
use App\Models\Inbox;

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

// Force DB host to localhost for this script
putenv('DB_HOST=127.0.0.1');
putenv('REDIS_HOST=127.0.0.1');
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['REDIS_HOST'] = '127.0.0.1';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$conversationId = 42;

echo "Checking data for conversation ID: $conversationId\n";

$conversation = Conversation::withoutGlobalScopes()->find(42);

if ($conversation) {
    echo "Conversation found.\n";
    echo "ID: " . $conversation->id . "\n";
    echo "Account ID: " . $conversation->account_id . "\n";
    echo "Contact ID: " . $conversation->contact_id . "\n";
    echo "Inbox ID: " . $conversation->inbox_id . "\n";

    // Check Contact directly
    $contact = Contact::withoutGlobalScopes()->find($conversation->contact_id);
    if ($contact) {
        echo "Raw Contact found. ID: " . $contact->id . ", Account ID: " . $contact->account_id . "\n";
    } else {
        echo "Raw Contact NOT found for ID: " . $conversation->contact_id . "\n";
    }

    // Check Inbox directly
    $inbox = Inbox::withoutGlobalScopes()->find($conversation->inbox_id);
    if ($inbox) {
        echo "Raw Inbox found. ID: " . $inbox->id . ", Account ID: " . $inbox->account_id . "\n";
        echo "Channel Type: " . $inbox->channel_type . "\n";
        echo "Channel ID: " . $inbox->channel_id . "\n";
        
        // Check Channel Webhook URL
        if ($inbox->channel_type === 'App\\Models\\Channel\\FacebookChannel') {
             $channel = \App\Models\Channel\FacebookChannel::withoutGlobalScopes()->find($inbox->channel_id);
             if ($channel) {
                 echo "Channel found. ID: " . $channel->id . "\n";
                 echo "Webhook URL: " . ($channel->webhook_url ? $channel->webhook_url : "NULL") . "\n";
             } else {
                 echo "Channel NOT found!\n";
             }
        }
    } else {
        echo "Raw Inbox NOT found for ID: " . $conversation->inbox_id . "\n";
    }

    // Check Relations
    if ($conversation->contact) {
         echo "Relation Contact found via model.\n";
    } else {
         echo "Relation Contact NOT found via model (Scope issue?).\n";
    }

    if ($conversation->inbox) {
         echo "Relation Inbox found via model.\n";
    } else {
         echo "Relation Inbox NOT found via model (Scope issue?).\n";
    }

    // Check ContactInbox
    $contactInbox = ContactInbox::where('contact_id', $conversation->contact_id)
        ->where('inbox_id', $conversation->inbox_id)
        ->first();
    
    if ($contactInbox) {
        echo "ContactInbox found. Source ID: " . $contactInbox->source_id . "\n";
    } else {
        echo "ContactInbox NOT found for Contact " . $conversation->contact_id . " and Inbox " . $conversation->inbox_id . "\n";
    }

} else {
    echo "Conversation 42 not found even without global scopes.\n";
}

echo "\nChecking recent messages for this conversation:\n";
$messages = Message::withoutGlobalScopes()
    ->where('conversation_id', $conversationId)
    ->orderBy('created_at', 'desc')
    ->take(5)
    ->get();

foreach ($messages as $msg) {
    echo "Msg ID: {$msg->id}, Type: {$msg->message_type}, Status: {$msg->status}, Content: {$msg->content}\n";
}
