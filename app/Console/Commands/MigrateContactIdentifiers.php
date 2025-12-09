<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateContactIdentifiers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contacts:migrate-identifiers {--dry-run : Executa sem fazer alterações}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migra identificadores de contatos do formato antigo (identifier) para os novos campos (identifier_facebook, identifier_instagram)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('🔍 Modo DRY-RUN: Nenhuma alteração será feita');
        }

        $this->info('🚀 Iniciando migração de identificadores de contatos...');
        $this->newLine();

        // Migra identificadores do Facebook
        $this->info('📘 Migrando identificadores do Facebook...');
        $facebookCount = $this->migrateFacebookIdentifiers($dryRun);
        $this->info("✅ Migrados {$facebookCount} contatos do Facebook");
        $this->newLine();

        // Migra identificadores do Instagram
        $this->info('📷 Migrando identificadores do Instagram...');
        $instagramCount = $this->migrateInstagramIdentifiers($dryRun);
        $this->info("✅ Migrados {$instagramCount} contatos do Instagram");
        $this->newLine();

        $total = $facebookCount + $instagramCount;
        $this->info("🎉 Migração concluída! Total: {$total} contatos migrados");
        
        if ($dryRun) {
            $this->warn('⚠️  Modo DRY-RUN: Execute sem --dry-run para aplicar as alterações');
        }

        return Command::SUCCESS;
    }

    /**
     * Migra identificadores do Facebook
     * 
     * @param bool $dryRun
     * @return int
     */
    protected function migrateFacebookIdentifiers(bool $dryRun): int
    {
        $contacts = DB::table('contacts')
            ->whereNotNull('identifier')
            ->where('identifier', 'like', 'facebook_%')
            ->whereNull('identifier_facebook') // Só migra se ainda não tiver identifier_facebook
            ->get();

        $count = 0;
        foreach ($contacts as $contact) {
            // Extrai o ID do Facebook do identifier (formato: "facebook_123456")
            $facebookId = str_replace('facebook_', '', $contact->identifier);
            
            // Valida que o ID não está vazio
            if (!empty($facebookId) && $facebookId !== '') {
                if (!$dryRun) {
                    DB::table('contacts')
                        ->where('id', $contact->id)
                        ->update([
                            'identifier_facebook' => $facebookId,
                            'updated_at' => now(),
                        ]);
                }
                
                $this->line("  → Contato ID {$contact->id}: '{$contact->identifier}' → identifier_facebook: '{$facebookId}'");
                $count++;
            }
        }

        return $count;
    }

    /**
     * Migra identificadores do Instagram
     * 
     * @param bool $dryRun
     * @return int
     */
    protected function migrateInstagramIdentifiers(bool $dryRun): int
    {
        $contacts = DB::table('contacts')
            ->whereNotNull('identifier')
            ->where('identifier', 'like', 'instagram_%')
            ->whereNull('identifier_instagram') // Só migra se ainda não tiver identifier_instagram
            ->get();

        $count = 0;
        foreach ($contacts as $contact) {
            // Extrai o ID do Instagram do identifier (formato: "instagram_123456")
            $instagramId = str_replace('instagram_', '', $contact->identifier);
            
            // Valida que o ID não está vazio
            if (!empty($instagramId) && $instagramId !== '') {
                if (!$dryRun) {
                    DB::table('contacts')
                        ->where('id', $contact->id)
                        ->update([
                            'identifier_instagram' => $instagramId,
                            'updated_at' => now(),
                        ]);
                }
                
                $this->line("  → Contato ID {$contact->id}: '{$contact->identifier}' → identifier_instagram: '{$instagramId}'");
                $count++;
            }
        }

        return $count;
    }
}
