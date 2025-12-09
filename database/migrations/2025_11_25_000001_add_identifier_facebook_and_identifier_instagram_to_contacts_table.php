<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('identifier_facebook')->nullable()->after('identifier');
            $table->string('identifier_instagram')->nullable()->after('identifier_facebook');
            
            // Índices para busca rápida
            $table->index(['account_id', 'identifier_facebook']);
            $table->index(['account_id', 'identifier_instagram']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'identifier_facebook']);
            $table->dropIndex(['account_id', 'identifier_instagram']);
            $table->dropColumn(['identifier_facebook', 'identifier_instagram']);
        });
    }
};

