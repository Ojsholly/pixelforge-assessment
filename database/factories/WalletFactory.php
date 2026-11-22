<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (): int => User::withoutEvents(fn (): int => User::factory()->create()->id),
            'balance' => 0,
        ];
    }

    public function withBalance(int $nanodollars): static
    {
        return $this->state(fn (): array => [
            'balance' => $nanodollars,
        ]);
    }
}
