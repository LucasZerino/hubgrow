<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'inbox_id' => Inbox::factory(),
            'conversation_id' => Conversation::factory(),
            'sender_id' => User::factory(),
            'message_type' => Message::TYPE_INCOMING,
            'content' => fake()->sentence(),
            'content_type' => Message::CONTENT_TYPE_TEXT,
            'source_id' => fake()->optional()->uuid(),
            'in_reply_to_external_id' => null,
            'status' => Message::STATUS_SENT,
            'external_error' => null,
            'content_attributes' => [],
            'private' => '{}',
        ];
    }
}

