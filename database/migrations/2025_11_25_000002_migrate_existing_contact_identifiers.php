<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Migra identificadores existentes do formato antigo (identifier) 
     * para os novos campos (identifier_facebook, identifier_instagram)
     * 
     * Mantém a estrutura: Instagram → identifier_instagram, Facebook → identifier_facebook
     */
    public function up(): void
    {
        \Illuminate\Support\Facades\Log::info('[MIGRATION] Iniciando migração de identificadores de contatos');

        // Migra identificadores do Facebook
        // Busca contatos com identifier no formato "facebook_XXXXX"
        $facebookContacts = DB::table('contacts')
            ->whereNotNull('identifier')
            ->where('identifier', 'like', 'facebook_%')
            ->whereNull('identifier_facebook') // Só migra se ainda não tiver identifier_facebook
            ->get();

        $facebookCount = 0;
        foreach ($facebookContacts as $contact) {
            // Extrai o ID do Facebook do identifier (formato: "facebook_123456")
            $facebookId = str_replace('facebook_', '', $contact->identifier);
            
            // Valida que o ID não está vazio e não contém apenas underscore
            if (!empty($facebookId) && $facebookId !== '') {
                DB::table('contacts')
                    ->where('id', $contact->id)
                    ->update([
                        'identifier_facebook' => $facebookId,
                        'updated_at' => now(),
                    ]);
                $facebookCount++;
                
                \Illuminate\Support\Facades\Log::info('[MIGRATION] Contato Facebook migrado', [
                    'contact_id' => $contact->id,
                    'identifier_old' => $contact->identifier,
                    'identifier_facebook' => $facebookId,
                ]);
            }
        }

        \Illuminate\Support\Facades\Log::info('[MIGRATION] Migrados identificadores do Facebook', [
            'count' => $facebookCount,
        ]);

        // Migra identificadores do Instagram
        // Busca contatos com identifier no formato "instagram_XXXXX"
        $instagramContacts = DB::table('contacts')
            ->whereNotNull('identifier')
            ->where('identifier', 'like', 'instagram_%')
            ->whereNull('identifier_instagram') // Só migra se ainda não tiver identifier_instagram
            ->get();

        $instagramCount = 0;
        foreach ($instagramContacts as $contact) {
            // Extrai o ID do Instagram do identifier (formato: "instagram_123456")
            $instagramId = str_replace('instagram_', '', $contact->identifier);
            
            // Valida que o ID não está vazio e não contém apenas underscore
            if (!empty($instagramId) && $instagramId !== '') {
                DB::table('contacts')
                    ->where('id', $contact->id)
                    ->update([
                        'identifier_instagram' => $instagramId,
                        'updated_at' => now(),
                    ]);
                $instagramCount++;
                
                \Illuminate\Support\Facades\Log::info('[MIGRATION] Contato Instagram migrado', [
                    'contact_id' => $contact->id,
                    'identifier_old' => $contact->identifier,
                    'identifier_instagram' => $instagramId,
                ]);
            }
        }

        \Illuminate\Support\Facades\Log::info('[MIGRATION] Migrados identificadores do Instagram', [
            'count' => $instagramCount,
        ]);

        \Illuminate\Support\Facades\Log::info('[MIGRATION] Migração de identificadores concluída', [
            'facebook_count' => $facebookCount,
            'instagram_count' => $instagramCount,
            'total' => $facebookCount + $instagramCount,
        ]);
    }

    /**
     * Reverse the migrations.
     * 
     * Não reverte a migração de dados, apenas remove os campos se necessário
     */
    public function down(): void
    {
        // Não reverte a migração de dados para evitar perda de informação
        // Os campos identifier_facebook e identifier_instagram serão removidos
        // pela migration anterior se necessário
        Log::info('[MIGRATION] Reversão de migração de identificadores - dados mantidos');
    }
};

