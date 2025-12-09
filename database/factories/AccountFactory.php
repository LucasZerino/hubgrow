<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'domain' => fake()->optional()->domainName(),
            'support_email' => fake()->optional()->companyEmail(),
            'locale' => \App\Models\Account::LOCALE_PT_BR,
            'status' => \App\Models\Account::STATUS_ACTIVE,
            'auto_resolve_duration' => fake()->optional()->numberBetween(1, 30),
            'custom_attributes' => [],
            'internal_attributes' => [],
            'settings' => [],
            'limits' => null,
            'feature_flags' => 0,
        ];
    }
}
