<?php

namespace App\Console\Commands;

use App\Models\AppLog;
use Illuminate\Console\Command;

class CheckInstagramLogs extends Command
{
    protected $signature = 'logs:instagram {--limit=30}';
    protected $description = 'Mostra logs do Instagram';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        
        $logs = AppLog::where('channel', 'instagram')
            ->orWhere('message', 'like', '%INSTAGRAM%')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($logs->isEmpty()) {
            $this->info('Nenhum log encontrado.');
            return 0;
        }

        foreach ($logs as $log) {
            $this->line("{$log->created_at} [{$log->level}] {$log->message}");
            if ($log->context) {
                $this->line('  Context: ' . json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            $this->line('');
        }

        return 0;
    }
}

