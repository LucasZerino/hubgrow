<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AccountUser>
 */
class AccountUserFactory extends Factory
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
            'user_id' => User::factory(),
            'role' => AccountUser::ROLE_AGENT,
            'active_at' => now(),
        ];
    }

    /**
     * Indica que o usuário é administrador
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AccountUser::ROLE_ADMINISTRATOR,
        ]);
    }
}
