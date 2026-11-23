<?php

namespace App\Support;

use App\Enums\TransactionDirection;
use App\Models\Generation;
use App\Models\Transaction;

final class TransactionPresenter
{
    /**
     * Ledger row for the credits page: signed amount, running balance, and a
     * link back to the owning generation when the entry is generation-scoped.
     *
     * @return array<string, mixed>
     */
    public function ledgerRow(Transaction $transaction): array
    {
        $transactionable = $transaction->transactionable;
        $isDebit = $transaction->direction === TransactionDirection::Debit;

        return [
            'id' => $transaction->id,
            'direction' => $transaction->direction->value,
            'type' => $transaction->type->value,
            'signed_amount_display' => ($isDebit ? '-' : '+').Money::formatDisplay($transaction->amount),
            'balance_after_display' => Money::formatDisplay($transaction->balance_after),
            'description' => $transaction->description,
            'generation_label' => $transactionable instanceof Generation ? "Generation #{$transactionable->id}" : null,
            'generation_url' => $transactionable instanceof Generation ? route('generations.show', $transactionable) : null,
            'created_at' => $transaction->created_at?->toIso8601String(),
            'created_at_human' => $transaction->created_at?->diffForHumans(),
        ];
    }
}
