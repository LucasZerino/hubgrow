<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pubsub_token')->unique()->nullable()->after('is_super_admin');
        });

        // Gera tokens para usuários existentes
        DB::table('users')->whereNull('pubsub_token')->get()->each(function ($user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['pubsub_token' => \Illuminate\Support\Str::random(32)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pubsub_token');
        });
    }
};

