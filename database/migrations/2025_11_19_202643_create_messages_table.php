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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->foreignId('inbox_id')->constrained()->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('sender_id')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('message_type')->default(0); // enum: incoming=0, outgoing=1, activity=2
            $table->text('content')->nullable();
            $table->string('content_type')->default('text'); // text, image, video, audio, file, etc
            $table->string('source_id')->nullable(); // ID da mensagem na plataforma externa
            $table->string('in_reply_to_external_id')->nullable(); // ID da mensagem respondida
            $table->integer('status')->default(0); // enum: sent=0, delivered=1, read=2, failed=3
            $table->string('external_error')->nullable(); // Erro da plataforma externa
            $table->json('content_attributes')->nullable(); // Metadados adicionais
            $table->json('private')->default('{}'); // Dados privados
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index('account_id');
            $table->index('inbox_id');
            $table->index('conversation_id');
            $table->index('sender_id');
            $table->index('source_id');
            $table->index(['account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
