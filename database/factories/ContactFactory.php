<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
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
            'name' => fake()->name(),
            'email' => fake()->optional()->email(),
            'phone_number' => fake()->optional()->phoneNumber(),
            'identifier' => fake()->optional()->uuid(),
            'avatar_url' => fake()->optional()->imageUrl(),
            'custom_attributes' => [],
            'additional_attributes' => [],
            'last_activity_at' => now(),
        ];
    }
}

