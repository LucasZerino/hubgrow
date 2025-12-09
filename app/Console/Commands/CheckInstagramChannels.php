<?php

namespace App\Console\Commands;

use App\Models\Channel\InstagramChannel;
use Illuminate\Console\Command;

class CheckInstagramChannels extends Command
{
    protected $signature = 'instagram:check-channels';
    protected $description = 'Lista todos os canais Instagram cadastrados';

    public function handle()
    {
        $channels = InstagramChannel::all(['id', 'instagram_id', 'account_id']);
        
        $this->info("Total de canais Instagram: {$channels->count()}");
        
        if ($channels->count() > 0) {
            $this->table(
                ['ID', 'Instagram ID', 'Account ID'],
                $channels->map(function ($c) {
                    return [$c->id, $c->instagram_id, $c->account_id];
                })->toArray()
            );
        } else {
            $this->warn('Nenhum canal Instagram encontrado!');
        }
        
        return 0;
    }
}

