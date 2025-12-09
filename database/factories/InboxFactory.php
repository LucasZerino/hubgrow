<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Inbox>
 */
class InboxFactory extends Factory
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
            'name' => fake()->words(3, true),
            'channel_type' => 'App\\Models\\Channel\\WebWidgetChannel',
            'channel_id' => fake()->numberBetween(1, 100),
            'email_address' => fake()->optional()->email(),
            'business_name' => fake()->optional()->company(),
            'timezone' => 'America/Sao_Paulo',
            'greeting_enabled' => false,
            'greeting_message' => null,
            'out_of_office_message' => null,
            'working_hours_enabled' => false,
            'enable_auto_assignment' => true,
            'auto_assignment_config' => [],
            'allow_messages_after_resolved' => true,
            'lock_to_single_conversation' => false,
            'csat_survey_enabled' => false,
            'csat_config' => [],
            'enable_email_collect' => true,
            'sender_name_type' => 0,
        ];
    }
}

