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
        Schema::table('web_widget_channels', function (Blueprint $table) {
            $table->string('widget_logo')->nullable()->after('website_url');
            $table->string('webhook_url')->nullable()->after('widget_logo');
            $table->text('greeting_message')->nullable()->after('welcome_tagline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('web_widget_channels', function (Blueprint $table) {
            $table->dropColumn(['widget_logo', 'webhook_url', 'greeting_message']);
        });
    }
};
