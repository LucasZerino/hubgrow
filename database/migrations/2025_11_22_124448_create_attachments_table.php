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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            
            // Tipo de arquivo: image=0, audio=1, video=2, file=3, location=4, fallback=5, share=6, story_mention=7, contact=8, ig_reel=9
            $table->integer('file_type')->default(0);
            
            // URL externa (usado para Instagram e outros serviços)
            $table->string('external_url', 2048)->nullable();
            
            // Coordenadas para localização
            $table->double('coordinates_lat')->default(0.0);
            $table->double('coordinates_long')->default(0.0);
            
            // Título fallback (para location, fallback, contact)
            $table->string('fallback_title')->nullable();
            
            // Extensão do arquivo
            $table->string('extension')->nullable();
            
            // Metadados adicionais (JSON)
            $table->json('meta')->nullable();
            
            // Campos para armazenamento de arquivo via Laravel Storage
            $table->string('file_path')->nullable(); // Caminho do arquivo no storage
            $table->string('file_name')->nullable(); // Nome original do arquivo
            $table->string('mime_type')->nullable(); // Tipo MIME
            $table->unsignedBigInteger('file_size')->nullable(); // Tamanho em bytes
            $table->json('file_metadata')->nullable(); // Metadados do arquivo (width, height, etc.)
            
            $table->timestamps();
            
            // Índices
            $table->index('account_id');
            $table->index('message_id');
            $table->index(['account_id', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
