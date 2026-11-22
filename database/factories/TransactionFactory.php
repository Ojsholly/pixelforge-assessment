<?php

namespace Database\Factories;

use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = 5 * Money::NANODOLLARS_PER_USD;

        return [
            'wallet_id' => Wallet::factory(),
            'transactionable_type' => null,
            'transactionable_id' => null,
            'direction' => TransactionDirection::Credit,
            'type' => TransactionType::Purchase,
            'amount' => $amount,
            'balance_after' => $amount,
            'description' => 'Credit purchase',
            'metadata' => null,
        ];
    }
}
