<?php

namespace App\Console\Commands;

use App\Models\Channel\FacebookChannel;
use App\Models\Channel\InstagramChannel;
use App\Models\Channel\WebWidgetChannel;
use App\Models\Channel\WhatsAppChannel;
use App\Models\Inbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteOrphanChannels extends Command
{
    protected $signature = 'channels:delete-orphans 
                            {--dry-run : Apenas mostra os channels que seriam deletados, sem deletar}
                            {--force : Força a deleção sem confirmação}';
    
    protected $description = 'Deleta channels órfãos (sem inbox associado)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        
        $this->info('🔍 Buscando channels órfãos...');
        $this->newLine();
        
        $orphans = $this->findOrphanChannels();
        
        if (empty($orphans)) {
            $this->info('✅ Nenhum channel órfão encontrado!');
            return 0;
        }
        
        $this->displayOrphans($orphans);
        
        if ($dryRun) {
            $this->warn('⚠️  Modo dry-run: nenhum channel foi deletado.');
            return 0;
        }
        
        if (!$force) {
            if (!$this->confirm('Deseja deletar estes channels?', false)) {
                $this->info('Operação cancelada.');
                return 0;
            }
        }
        
        $deleted = $this->deleteOrphans($orphans);
        
        $this->newLine();
        $this->info("✅ {$deleted} channel(s) deletado(s) com sucesso!");
        
        return 0;
    }

    /**
     * Encontra todos os channels órfãos
     */
    protected function findOrphanChannels(): array
    {
        $orphans = [];
        
        // Instagram Channels
        $instagramChannels = InstagramChannel::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
            ->get();
        
        foreach ($instagramChannels as $channel) {
            $inbox = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                ->where('channel_type', InstagramChannel::class)
                ->where('channel_id', $channel->id)
                ->first();
            
            if (!$inbox) {
                $orphans[] = [
                    'type' => 'Instagram',
                    'id' => $channel->id,
                    'account_id' => $channel->account_id,
                    'identifier' => $channel->instagram_id ?? 'N/A',
                    'channel' => $channel,
                ];
            }
        }
        
        // WhatsApp Channels
        $whatsappChannels = WhatsAppChannel::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
            ->get();
        
        foreach ($whatsappChannels as $channel) {
            $inbox = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                ->where('channel_type', WhatsAppChannel::class)
                ->where('channel_id', $channel->id)
                ->first();
            
            if (!$inbox) {
                $orphans[] = [
                    'type' => 'WhatsApp',
                    'id' => $channel->id,
                    'account_id' => $channel->account_id,
                    'identifier' => $channel->phone_number ?? 'N/A',
                    'channel' => $channel,
                ];
            }
        }
        
        // Facebook Channels
        $facebookChannels = FacebookChannel::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
            ->get();
        
        foreach ($facebookChannels as $channel) {
            $inbox = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                ->where('channel_type', FacebookChannel::class)
                ->where('channel_id', $channel->id)
                ->first();
            
            if (!$inbox) {
                $orphans[] = [
                    'type' => 'Facebook',
                    'id' => $channel->id,
                    'account_id' => $channel->account_id,
                    'identifier' => $channel->page_id ?? 'N/A',
                    'channel' => $channel,
                ];
            }
        }
        
        // WebWidget Channels
        $webWidgetChannels = WebWidgetChannel::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
            ->get();
        
        foreach ($webWidgetChannels as $channel) {
            $inbox = Inbox::withoutGlobalScope(\App\Models\Concerns\HasAccountScope::class)
                ->where('channel_type', WebWidgetChannel::class)
                ->where('channel_id', $channel->id)
                ->first();
            
            if (!$inbox) {
                $orphans[] = [
                    'type' => 'WebWidget',
                    'id' => $channel->id,
                    'account_id' => $channel->account_id,
                    'identifier' => $channel->website_url ?? 'N/A',
                    'channel' => $channel,
                ];
            }
        }
        
        return $orphans;
    }

    /**
     * Exibe os channels órfãos encontrados
     */
    protected function displayOrphans(array $orphans): void
    {
        $this->warn("⚠️  Encontrados " . count($orphans) . " channel(s) órfão(s):");
        $this->newLine();
        
        $headers = ['Tipo', 'ID', 'Account ID', 'Identificador'];
        $rows = [];
        
        foreach ($orphans as $orphan) {
            $rows[] = [
                $orphan['type'],
                $orphan['id'],
                $orphan['account_id'],
                $orphan['identifier'],
            ];
        }
        
        $this->table($headers, $rows);
        $this->newLine();
    }

    /**
     * Deleta os channels órfãos
     */
    protected function deleteOrphans(array $orphans): int
    {
        $deleted = 0;
        
        foreach ($orphans as $orphan) {
            try {
                $channel = $orphan['channel'];
                
                // Remove webhooks antes de deletar (se o método existir)
                if (method_exists($channel, 'teardownWebhooks')) {
                    try {
                        $channel->teardownWebhooks();
                    } catch (\Exception $e) {
                        Log::warning('[DELETE_ORPHAN_CHANNELS] Erro ao remover webhooks', [
                            'channel_type' => $orphan['type'],
                            'channel_id' => $channel->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                $channel->delete();
                $deleted++;
                
                $this->line("  ✓ Deletado: {$orphan['type']} #{$channel->id} (Account: {$orphan['account_id']})");
                
            } catch (\Exception $e) {
                $this->error("  ✗ Erro ao deletar {$orphan['type']} #{$orphan['id']}: {$e->getMessage()}");
                Log::error('[DELETE_ORPHAN_CHANNELS] Erro ao deletar channel', [
                    'channel_type' => $orphan['type'],
                    'channel_id' => $orphan['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $deleted;
    }
}

