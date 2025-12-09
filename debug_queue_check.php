<?php

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force Redis host to localhost for both database and queue config
config(['database.redis.default.host' => '127.0.0.1']);
config(['database.redis.cache.host' => '127.0.0.1']);
putenv('REDIS_HOST=127.0.0.1');
$_ENV['REDIS_HOST'] = '127.0.0.1';

echo "Checking Queue Status...\n";
echo "Queue Connection: " . config('queue.default') . "\n";

try {
    $connection = Queue::connection('redis');
    $defaultSize = $connection->size('default');
    $lowSize = $connection->size('low');
    $highSize = $connection->size('high');
    
    echo "Pending Jobs in 'default': $defaultSize\n";
    echo "Pending Jobs in 'low': $lowSize\n";
    echo "Pending Jobs in 'high': $highSize\n";
    
} catch (\Exception $e) {
    echo "Error checking queue: " . $e->getMessage() . "\n";
}
