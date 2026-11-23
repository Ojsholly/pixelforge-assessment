<?php

namespace App\Services;

use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Single owner of all wallet balance mutations. Every change is made inside a
 * DB transaction under lockForUpdate() and writes an immutable ledger row with
 * the post-change balance_after. Methods nest safely inside caller transactions.
 */
final readonly class WalletService
{
    public function ensureWallet(User $user): Wallet
    {
        try {
            /** @var Wallet $wallet */
            $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id]);

            // The inserted row takes balance from the DB default (0) — reload so
            // consumers never read a null balance off the in-memory model.
            if ($wallet->wasRecentlyCreated) {
                $wallet->refresh();
            }

            return $wallet;
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won the insert race — fetch the winner's row.
            /** @var Wallet $wallet */
            $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();

            return $wallet;
        }
    }

    /**
     * Credit funds into a wallet with a `purchase` ledger row.
     */
    public function purchase(User $user, int $amountNanodollars, string $description): Transaction
    {
        if ($amountNanodollars <= 0) {
            throw new InvalidArgumentException('Purchase amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amountNanodollars, $description): Transaction {
            $wallet = $this->lockWallet($user->id);
            $wallet->balance += $amountNanodollars;
            $wallet->save();

            return $this->record($wallet, null, TransactionDirection::Credit, TransactionType::Purchase, $amountNanodollars, $description);
        });
    }

    /**
     * Debit `estimated_max_user_cost` as a hold on the generation's reserved
     * amount. Throws ValidationException (rolled back, rendered as a 422 form
     * error) when the wallet is under-funded — concurrent submissions cannot
     * overdraft because the check happens under the row lock.
     */
    public function holdForGeneration(User $user, Generation $generation, int $amountNanodollars): ?Transaction
    {
        return DB::transaction(function () use ($user, $generation, $amountNanodollars): ?Transaction {
            $wallet = $this->lockWallet($user->id);

            if ($wallet->balance < $amountNanodollars) {
                throw ValidationException::withMessages([
                    'balance' => 'Insufficient wallet balance: this generation requires up to '
                        .Money::formatDisplay($amountNanodollars)
                        .', your balance is '.Money::formatDisplay($wallet->balance).'.',
                ]);
            }

            if ($amountNanodollars <= 0) {
                return null;
            }

            $wallet->balance -= $amountNanodollars;
            $wallet->save();

            $generation->reserved_amount = $amountNanodollars;
            $generation->save();

            return $this->record($wallet, $generation, TransactionDirection::Debit, TransactionType::Hold, $amountNanodollars, "Hold for generation #{$generation->id}");
        });
    }

    /**
     * Settle a succeeded generation: release the surplus when the hold exceeds
     * the billed cost, or capture the (wallet-capped) shortfall when it
     * exceeds the hold. Clears reserved_amount either way.
     */
    public function settleGeneration(Generation $generation, int $actualUserCostNanodollars): void
    {
        if ($generation->reserved_amount <= 0) {
            return;
        }

        DB::transaction(function () use ($generation, $actualUserCostNanodollars): void {
            $wallet = $this->walletForGeneration($generation);

            if ($generation->reserved_amount > $actualUserCostNanodollars) {
                $surplus = $generation->reserved_amount - $actualUserCostNanodollars;
                $wallet->balance += $surplus;
                $wallet->save();

                $this->record($wallet, $generation, TransactionDirection::Credit, TransactionType::Release, $surplus, "Released unused hold for generation #{$generation->id}");
            } elseif ($actualUserCostNanodollars > $generation->reserved_amount) {
                $shortfall = min($wallet->balance, $actualUserCostNanodollars - $generation->reserved_amount);

                if ($shortfall > 0) {
                    $wallet->balance -= $shortfall;
                    $wallet->save();

                    $this->record($wallet, $generation, TransactionDirection::Debit, TransactionType::Capture, $shortfall, "Captured actual cost shortfall for generation #{$generation->id}");
                }
            }

            $generation->reserved_amount = 0;
            $generation->save();
        });
    }

    /**
     * Refund 100% of a generation's reserved amount (failed or rejected).
     */
    public function releaseForGeneration(Generation $generation, string $reason): void
    {
        if ($generation->reserved_amount <= 0) {
            return;
        }

        DB::transaction(function () use ($generation, $reason): void {
            $wallet = $this->walletForGeneration($generation);

            $refund = $generation->reserved_amount;
            $wallet->balance += $refund;
            $wallet->save();

            $this->record($wallet, $generation, TransactionDirection::Credit, TransactionType::Release, $refund, $reason);

            $generation->reserved_amount = 0;
            $generation->save();
        });
    }

    private function lockWallet(int $userId): Wallet
    {
        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();

        return $wallet;
    }

    /**
     * Resolve the wallet a generation's hold lives on. Orphaned generations
     * (owner account deleted, user_id nulled) resolve through their hold
     * transaction so settlement/refunds still hit the anonymized wallet.
     */
    private function walletForGeneration(Generation $generation): Wallet
    {
        if ($generation->user_id !== null) {
            return $this->lockWallet($generation->user_id);
        }

        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->whereHas('transactions', function ($query) use ($generation): void {
                $query->where('transactionable_type', $generation->getMorphClass())
                    ->where('transactionable_id', $generation->id)
                    ->where('type', TransactionType::Hold->value);
            })
            ->lockForUpdate()
            ->firstOrFail();

        return $wallet;
    }

    private function record(Wallet $wallet, ?Generation $generation, TransactionDirection $direction, TransactionType $type, int $amount, string $description): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = Transaction::forceCreate([
            'wallet_id' => $wallet->id,
            'transactionable_type' => $generation?->getMorphClass(),
            'transactionable_id' => $generation?->id,
            'direction' => $direction,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $wallet->balance,
            'description' => $description,
        ]);

        return $transaction;
    }
}
