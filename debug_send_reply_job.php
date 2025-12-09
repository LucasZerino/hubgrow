<?php

use App\Jobs\SendReplyJob;
use App\Models\Message;
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

$messageId = 339;

echo "Starting SendReplyJob for Message ID: $messageId\n";

// Verify message exists
$message = Message::withoutGlobalScopes()->find($messageId);
if (!$message) {
    echo "Message not found!\n";
    exit(1);
}

echo "Message found. Content: " . $message->content . "\n";
echo "Account ID: " . $message->account_id . "\n";
echo "Inbox ID: " . $message->inbox_id . "\n";

try {
    $job = new SendReplyJob($messageId);
    $job->handle();
    echo "Job executed successfully.\n";
} catch (\Exception $e) {
    echo "Job failed with exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
