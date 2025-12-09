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
        Schema::create('inboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('channel_type'); // WhatsApp, Instagram, Facebook, WebWidget
            $table->unsignedBigInteger('channel_id'); // ID do canal específico (polymorphic)
            $table->string('email_address')->nullable();
            $table->string('business_name')->nullable();
            $table->string('timezone')->default('UTC');
            $table->boolean('greeting_enabled')->default(false);
            $table->text('greeting_message')->nullable();
            $table->text('out_of_office_message')->nullable();
            $table->boolean('working_hours_enabled')->default(false);
            $table->boolean('enable_auto_assignment')->default(true);
            $table->json('auto_assignment_config')->nullable();
            $table->boolean('allow_messages_after_resolved')->default(true);
            $table->boolean('lock_to_single_conversation')->default(false);
            $table->boolean('csat_survey_enabled')->default(false);
            $table->json('csat_config')->default('{}');
            $table->boolean('enable_email_collect')->default(true);
            $table->integer('sender_name_type')->default(0); // enum: friendly=0
            $table->timestamps();

            $table->index(['account_id']);
            $table->index(['channel_id', 'channel_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inboxes');
    }
};
