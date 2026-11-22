<?php

namespace App\Actions\Generations;

use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\CreateGenerationInput;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Exceptions\ProviderException;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PricingCalculator;
use App\States\Generation\Failed;
use App\Support\PromptSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateGenerationAction
{
    public function __construct(
        private ImageGenerationProvider $provider,
        private PricingCalculator $calculator,
        private ApplyGenerationStateAction $applyState,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     */
    public function execute(
        User $user,
        string $rawPrompt,
        ImageSize $size,
        ImageQuality $quality,
        array $referenceImages = [],
    ): Generation {
        $prompt = PromptSanitizer::sanitize($rawPrompt);
        $pricing = $this->provider->getPricing();
        $estimate = $this->calculator->estimate($prompt, $size, $quality, $referenceImages, $pricing);

        // TX1: Pre-create generation row (and hold funds if funded) before outbound HTTP call
        // so any racing webhook can match via metadata.local_generation_id.
        /** @var Generation $generation */
        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate): Generation {
            /** @var Wallet $wallet */
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $generation = Generation::create([
                'user_id' => $user->id,
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'rates_snapshot' => $estimate->ratesSnapshot,
                'estimated_tokens' => $estimate->estimatedTokensArray(),
                'estimated_min_user_cost' => $estimate->userMinNanodollars,
                'estimated_max_user_cost' => $estimate->userMaxNanodollars,
            ]);

            if ($wallet->balance >= $estimate->userMaxNanodollars && $estimate->userMaxNanodollars > 0) {
                $wallet->balance -= $estimate->userMaxNanodollars;
                $wallet->save();

                $generation->reserved_amount = $estimate->userMaxNanodollars;
                $generation->save();

                Transaction::forceCreate([
                    'wallet_id' => $wallet->id,
                    'transactionable_type' => $generation->getMorphClass(),
                    'transactionable_id' => $generation->id,
                    'direction' => TransactionDirection::Debit,
                    'type' => TransactionType::Hold,
                    'amount' => $estimate->userMaxNanodollars,
                    'balance_after' => $wallet->balance,
                    'description' => "Hold for generation #{$generation->id}",
                ]);
            }

            return $generation;
        });

        try {
            $providerData = $this->provider->createGeneration(new CreateGenerationInput(
                prompt: $prompt,
                size: $size,
                quality: $quality,
                idempotencyKey: $generation->idempotency_key,
                referenceImages: $referenceImages,
                metadata: [
                    'local_generation_id' => (string) $generation->id,
                    'user_id' => (string) $user->id,
                ],
            ));
        } catch (ProviderException $providerException) {
            $this->failAndRelease($generation, $providerException);

            throw $providerException;
        }

        // TX2: Reconcile accepted provider response without regressing any earlier webhook.
        return $this->applyState->execute($generation, $providerData);
    }

    private function failAndRelease(Generation $generation, ProviderException $exception): void
    {
        DB::transaction(function () use ($generation, $exception): void {
            /** @var Generation $locked */
            $locked = Generation::query()
                ->whereKey($generation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canTransitionTo(Failed::class)) {
                return;
            }

            $locked->status->transitionTo(Failed::class);

            $locked->error_code = $exception->errorCode ?? 'provider_error';
            $locked->error_message = $exception->getMessage();
            $locked->actual_provider_cost = 0;
            $locked->actual_user_cost = 0;
            $locked->completed_at = CarbonImmutable::now();

            if ($locked->reserved_amount > 0) {
                /** @var Wallet $wallet */
                $wallet = Wallet::query()
                    ->where('user_id', $locked->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $refund = $locked->reserved_amount;
                $wallet->balance += $refund;
                $wallet->save();

                Transaction::forceCreate([
                    'wallet_id' => $wallet->id,
                    'transactionable_type' => $locked->getMorphClass(),
                    'transactionable_id' => $locked->id,
                    'direction' => TransactionDirection::Credit,
                    'type' => TransactionType::Release,
                    'amount' => $refund,
                    'balance_after' => $wallet->balance,
                    'description' => "Full refund for rejected generation #{$locked->id}",
                ]);

                $locked->reserved_amount = 0;
            }

            $locked->save();
        });
    }
}
