<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Altera sender_id para relacionamento polimórfico (pode ser User, Contact ou AgentBot)
     * Seguindo o padrão do Chatwoot original
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Remove a foreign key antiga
            $table->dropForeign(['sender_id']);
            
            // Adiciona sender_type para relacionamento polimórfico
            $table->string('sender_type')->nullable()->after('sender_id');
            
            // Reindexa com sender_type
            $table->dropIndex(['sender_id']);
            $table->index(['sender_type', 'sender_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Remove índice polimórfico
            $table->dropIndex(['sender_type', 'sender_id']);
            
            // Remove sender_type
            $table->dropColumn('sender_type');
            
            // Restaura foreign key para users
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('set null');
            $table->index('sender_id');
        });
    }
};
