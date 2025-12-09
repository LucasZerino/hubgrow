<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('http_logs');
        
        // Also try dropping from public schema explicitly if needed
        DB::statement('DROP TABLE IF EXISTS public.http_logs');

        Schema::create('http_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level')->default('INFO');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('channel')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('http_logs');
    }
};
