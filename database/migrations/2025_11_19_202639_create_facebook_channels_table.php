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
        Schema::create('facebook_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->string('page_id');
            $table->text('page_access_token'); // Criptografado
            $table->text('user_access_token'); // Criptografado
            $table->string('instagram_id')->nullable(); // Se conectado ao Instagram
            $table->timestamps();

            $table->unique(['account_id', 'page_id']);
            $table->index('account_id');
            $table->index('page_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_channels');
    }
};
