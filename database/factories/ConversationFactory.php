<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Inbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
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
            'contact_id' => Contact::factory(),
            'contact_inbox_id' => null,
            'display_id' => fake()->numberBetween(1000, 9999),
            'status' => Conversation::STATUS_OPEN,
            'priority' => Conversation::PRIORITY_MEDIUM,
            'assignee_id' => null,
            'last_activity_at' => now(),
            'snoozed_until' => null,
            'custom_attributes' => [],
            'additional_attributes' => [],
        ];
    }
}

