<?php

namespace App\Actions\Generations;

use App\Data\Pixelforge\ProviderGenerationData;
use App\Data\Pixelforge\ProviderUsageData;
use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\PricingCalculator;
use App\States\Generation\Failed;
use App\States\Generation\GenerationState;
use App\States\Generation\Succeeded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ApplyGenerationStateAction
{
    public function __construct(
        private PricingCalculator $calculator,
    ) {}

    public function execute(Generation $generation, ProviderGenerationData $data): Generation
    {
        return DB::transaction(function () use ($generation, $data): Generation {
            /** @var Generation $locked */
            $locked = Generation::query()
                ->whereKey($generation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->provider_id ??= $data->id;

            if ($locked->provider_created_at === null && $data->createdAt !== null) {
                $locked->provider_created_at = CarbonImmutable::parse($data->createdAt);
            }

            if ($locked->started_at === null && $data->startedAt !== null) {
                $locked->started_at = CarbonImmutable::parse($data->startedAt);
            }

            $targetClass = GenerationState::fromStatus($data->status);

            if ($targetClass === $locked->status::class || ! $locked->status->canTransitionTo($targetClass)) {
                $locked->save();

                return $locked;
            }

            $locked->status->transitionTo($targetClass);

            if ($targetClass === Succeeded::class) {
                $this->handleSucceeded($locked, $data);
            } elseif ($targetClass === Failed::class) {
                $this->handleFailed($locked, $data);
            }

            $locked->save();

            return $locked;
        });
    }

    private function handleSucceeded(Generation $generation, ProviderGenerationData $data): void
    {
        $providerCost = $data->usage instanceof ProviderUsageData
            ? $this->calculator->calculateActualProviderCost($data->usage, $generation->rates_snapshot)
            : 0;

        $userCost = $this->calculator->calculateActualUserCost($providerCost);

        $generation->actual_usage = $data->usage?->toArray();
        $generation->actual_provider_cost = $providerCost;
        $generation->actual_user_cost = $userCost;
        $generation->image_url = $data->imageUrl;
        $generation->image_url_expires_at = $data->imageUrlExpiresAt !== null
            ? CarbonImmutable::parse($data->imageUrlExpiresAt)
            : null;
        $generation->completed_at = $data->completedAt !== null
            ? CarbonImmutable::parse($data->completedAt)
            : CarbonImmutable::now();

        if ($generation->reserved_amount <= 0) {
            return;
        }

        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->where('user_id', $generation->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($generation->reserved_amount > $userCost) {
            $surplus = $generation->reserved_amount - $userCost;
            $wallet->balance += $surplus;
            $wallet->save();

            Transaction::forceCreate([
                'wallet_id' => $wallet->id,
                'transactionable_type' => $generation->getMorphClass(),
                'transactionable_id' => $generation->id,
                'direction' => TransactionDirection::Credit,
                'type' => TransactionType::Release,
                'amount' => $surplus,
                'balance_after' => $wallet->balance,
                'description' => "Released unused hold for generation #{$generation->id}",
            ]);
        } elseif ($userCost > $generation->reserved_amount) {
            $shortfall = min($wallet->balance, $userCost - $generation->reserved_amount);

            if ($shortfall > 0) {
                $wallet->balance -= $shortfall;
                $wallet->save();

                Transaction::forceCreate([
                    'wallet_id' => $wallet->id,
                    'transactionable_type' => $generation->getMorphClass(),
                    'transactionable_id' => $generation->id,
                    'direction' => TransactionDirection::Debit,
                    'type' => TransactionType::Capture,
                    'amount' => $shortfall,
                    'balance_after' => $wallet->balance,
                    'description' => "Captured actual cost shortfall for generation #{$generation->id}",
                ]);
            }
        }

        $generation->reserved_amount = 0;
    }

    private function handleFailed(Generation $generation, ProviderGenerationData $data): void
    {
        $generation->error_code = $data->errorCode;
        $generation->error_message = $data->errorMessage;
        $generation->actual_provider_cost = 0;
        $generation->actual_user_cost = 0;
        $generation->completed_at = $data->completedAt !== null
            ? CarbonImmutable::parse($data->completedAt)
            : CarbonImmutable::now();

        if ($generation->reserved_amount <= 0) {
            return;
        }

        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->where('user_id', $generation->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        $refund = $generation->reserved_amount;
        $wallet->balance += $refund;
        $wallet->save();

        Transaction::forceCreate([
            'wallet_id' => $wallet->id,
            'transactionable_type' => $generation->getMorphClass(),
            'transactionable_id' => $generation->id,
            'direction' => TransactionDirection::Credit,
            'type' => TransactionType::Release,
            'amount' => $refund,
            'balance_after' => $wallet->balance,
            'description' => "Full refund for failed generation #{$generation->id}",
        ]);

        $generation->reserved_amount = 0;
    }
}
