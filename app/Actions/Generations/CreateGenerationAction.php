<?php

namespace App\Actions\Generations;

use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\CreateGenerationInput;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Exceptions\ProviderException;
use App\Models\Generation;
use App\Models\User;
use App\Services\PricingCalculator;
use App\Services\WalletService;
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
        private WalletService $wallets,
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

        // TX1: Pre-create generation row and hold estimated_max_user_cost before
        // the outbound HTTP call so any racing webhook can match via
        // metadata.local_generation_id. Under-funded wallets are rejected inside
        // the wallet lock and roll back the generation row with them.
        /** @var Generation $generation */
        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate): Generation {
            // Serialize with account deletion: if the owner row is gone (its
            // delete held this lock and already committed) abort before the
            // row, hold, or provider call exist to be orphaned.
            User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

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

            $this->wallets->holdForGeneration($user, $generation, $estimate->userMaxNanodollars);

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

            $this->wallets->releaseForGeneration($locked, "Full refund for rejected generation #{$locked->id}");

            $locked->save();
        });
    }
}
