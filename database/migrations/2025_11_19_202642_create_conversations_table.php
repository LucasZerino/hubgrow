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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->foreignId('inbox_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_inbox_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('display_id'); // ID de exibição único por account
            $table->integer('status')->default(0); // enum: open=0, resolved=1, pending=2
            $table->integer('priority')->default(0); // enum: low=0, medium=1, high=2, urgent=3
            $table->foreignId('assignee_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->json('custom_attributes')->nullable();
            $table->json('additional_attributes')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index('inbox_id');
            $table->index('contact_id');
            $table->index('assignee_id');
            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'display_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
