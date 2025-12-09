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
        Schema::table('inboxes', function (Blueprint $table) {
            // Torna channel_id nullable para permitir criar inbox do Instagram sem channel_id
            // (o channel será criado depois via OAuth)
            $table->unsignedBigInteger('channel_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            // Remove nullable (volta ao estado original)
            // Nota: Isso pode falhar se houver inboxes com channel_id NULL
            $table->unsignedBigInteger('channel_id')->nullable(false)->change();
        });
    }
};
