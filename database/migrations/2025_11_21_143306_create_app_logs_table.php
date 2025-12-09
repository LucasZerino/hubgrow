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
        Schema::create('app_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20)->index(); // info, warning, error, etc.
            $table->text('message');
            $table->jsonb('context')->nullable(); // Dados adicionais (array)
            $table->unsignedBigInteger('account_id')->nullable()->index(); // Para multi-tenancy
            $table->unsignedBigInteger('user_id')->nullable(); // Usuário que gerou o log (se aplicável)
            $table->string('channel', 50)->nullable()->index(); // instagram, webhook, message, etc.
            $table->timestamp('created_at')->index();
        });

        // Índices compostos para queries comuns
        Schema::table('app_logs', function (Blueprint $table) {
            $table->index(['account_id', 'created_at']);
            $table->index(['channel', 'level', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_logs');
    }
};
