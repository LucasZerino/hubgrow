<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove o campo identifier antigo, já que agora usamos
     * identifier_facebook e identifier_instagram separadamente
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Remove o índice primeiro
            $table->dropIndex(['account_id', 'identifier']);
            
            // Remove a coluna
            $table->dropColumn('identifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Recria a coluna
            $table->string('identifier')->nullable()->after('phone_number');
            
            // Recria o índice
            $table->index(['account_id', 'identifier']);
        });
    }
};

