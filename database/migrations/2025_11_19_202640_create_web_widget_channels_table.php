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
        Schema::create('web_widget_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->string('website_token')->unique();
            $table->string('hmac_token')->unique()->nullable();
            $table->string('website_url');
            $table->string('widget_color')->default('#1f93ff');
            $table->string('welcome_title')->nullable();
            $table->string('welcome_tagline')->nullable();
            $table->integer('reply_time')->default(0); // enum: in_a_few_minutes=0, in_a_few_hours=1, in_a_day=2
            $table->boolean('pre_chat_form_enabled')->default(false);
            $table->json('pre_chat_form_options')->nullable();
            $table->boolean('continuity_via_email')->default(true);
            $table->boolean('hmac_mandatory')->default(false);
            $table->text('allowed_domains')->nullable();
            $table->integer('feature_flags')->default(7); // Bit flags para features
            $table->timestamps();

            $table->index('account_id');
            $table->index('website_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_widget_channels');
    }
};
