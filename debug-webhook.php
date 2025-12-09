<?php

require __DIR__.'/backend/vendor/autoload.php';

$app = require_once __DIR__.'/backend/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Models\Inbox;
use App\Models\Channel\InstagramChannel;

echo "=== DEBUG WEBHOOK ===\n";

// 1. Check Database Connection
try {
    DB::connection()->getPdo();
    echo "[OK] Database connection successful\n";
} catch (\Exception $e) {
    echo "[ERROR] Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Check Redis Connection
try {
    Redis::connection()->ping();
    echo "[OK] Redis connection successful\n";
} catch (\Exception $e) {
    echo "[ERROR] Redis connection failed: " . $e->getMessage() . "\n";
}

// 3. Check Inboxes and Webhook URLs
$inboxes = Inbox::where('channel_type', InstagramChannel::class)->get();
echo "\nFound " . $inboxes->count() . " Instagram inboxes:\n";

foreach ($inboxes as $inbox) {
    echo "Inbox ID: {$inbox->id} (Account: {$inbox->account_id})\n";
    $channel = $inbox->channel;
    if ($channel) {
        echo "  Channel ID: {$channel->id}\n";
        echo "  Instagram ID: {$channel->instagram_id}\n";
        echo "  Webhook URL: " . ($channel->webhook_url ?? 'NULL') . "\n";
    } else {
        echo "  [ERROR] Channel not found for inbox!\n";
    }
}

// 4. Check Failed Jobs
try {
    $failedJobs = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(5)->get();
    echo "\nLast 5 Failed Jobs:\n";
    foreach ($failedJobs as $job) {
        echo "  ID: {$job->id}\n";
        echo "  Connection: {$job->connection}\n";
        echo "  Queue: {$job->queue}\n";
        echo "  Payload: " . substr($job->payload, 0, 100) . "...\n";
        echo "  Exception: " . substr($job->exception, 0, 100) . "...\n";
        echo "  Failed At: {$job->failed_at}\n";
        echo "-------------------\n";
    }
} catch (\Exception $e) {
    echo "[ERROR] Could not check failed_jobs: " . $e->getMessage() . "\n";
}

// 5. Check Pending Jobs in Redis
try {
    $queues = ['default', 'high', 'low', 'medium'];
    echo "\nPending Jobs in Redis:\n";
    foreach ($queues as $queue) {
        $count = Redis::connection()->llen('queues:' . $queue);
        echo "  Queue '{$queue}': {$count} jobs\n";
    }
} catch (\Exception $e) {
    echo "[ERROR] Could not check Redis queues: " . $e->getMessage() . "\n";
}
