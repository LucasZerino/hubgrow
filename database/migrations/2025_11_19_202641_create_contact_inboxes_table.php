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
        Schema::create('contact_inboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->foreignId('inbox_id')->constrained()->onDelete('cascade');
            $table->string('source_id'); // ID único na plataforma (WhatsApp, Instagram, etc)
            $table->json('hmac_verified')->nullable();
            $table->timestamps();

            $table->unique(['inbox_id', 'source_id']);
            $table->index('contact_id');
            $table->index('inbox_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_inboxes');
    }
};
