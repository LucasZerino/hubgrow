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
        Schema::create('whatsapp_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->string('phone_number')->unique();
            $table->string('provider')->default('whatsapp_cloud'); // whatsapp_cloud, default
            $table->json('provider_config'); // business_account_id, phone_number_id, api_key, webhook_verify_token
            $table->json('message_templates')->nullable();
            $table->timestamp('message_templates_last_updated')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index('phone_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_channels');
    }
};
