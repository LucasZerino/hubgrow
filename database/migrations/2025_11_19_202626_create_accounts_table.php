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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain', 100)->nullable();
            $table->string('support_email', 100)->nullable();
            $table->integer('locale')->default(0); // enum
            $table->integer('status')->default(0); // enum: active=0, suspended=1
            $table->integer('auto_resolve_duration')->nullable();
            $table->json('custom_attributes')->nullable();
            $table->json('internal_attributes')->default('{}');
            $table->json('settings')->nullable();
            $table->json('limits')->nullable();
            $table->bigInteger('feature_flags')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
