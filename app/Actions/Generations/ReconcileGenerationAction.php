<?php

namespace App\Actions\Generations;

use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Enums\GenerationStatus;
use App\Exceptions\ProviderException;
use App\Models\Generation;
use DateTimeInterface;
use Throwable;

/**
 * Polls the provider for one stale generation and applies the outcome.
 * Definitive outcomes (404, or still-pending past --max-age) become a
 * synthetic failed payload; transient failures return false so the
 * generation is retried on the next scheduled run unless it has exceeded
 * --hard-max-age, which bounds wallet holds.
 */
final readonly class ReconcileGenerationAction
{
    public function __construct(
        private ImageGenerationProvider $provider,
        private ApplyGenerationStateAction $applyState,
    ) {}

    /**
     * @return bool whether the generation moved to a new status
     */
    public function execute(Generation $generation, DateTimeInterface $timeoutCutoff, DateTimeInterface $outageCutoff): bool
    {
        $providerId = $generation->provider_id;

        if ($providerId === null) {
            return false;
        }

        $previousStatus = $generation->status->status();

        $providerData = $this->resolveProviderData($generation, $providerId, $timeoutCutoff, $outageCutoff);

        if (! $providerData instanceof ProviderGenerationData) {
            return false;
        }

        $result = rescue(
            fn (): Generation => $this->applyState->execute($generation, $providerData),
            report: true,
        );

        return $result instanceof Generation && $result->status->status() !== $previousStatus;
    }

    private function resolveProviderData(
        Generation $generation,
        string $providerId,
        DateTimeInterface $timeoutCutoff,
        DateTimeInterface $outageCutoff,
    ): ?ProviderGenerationData {
        try {
            $providerData = $this->provider->getGeneration($providerId);
        } catch (ProviderException $exception) {
            report($exception);

            if ($exception->statusCode === 404) {
                return $this->failedData($generation, $providerId, 'reconciliation_not_found', 'The provider reports this generation no longer exists.');
            }

            return $this->unreachableData($generation, $providerId, $outageCutoff);
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->unreachableData($generation, $providerId, $outageCutoff);
        }

        if (
            in_array($providerData->status, [GenerationStatus::Queued, GenerationStatus::Processing], true)
            && $generation->created_at !== null
            && $generation->created_at->lte($timeoutCutoff)
        ) {
            return $this->failedData($generation, $providerId, 'reconciliation_timeout', 'Generation timed out awaiting provider completion.');
        }

        return $providerData;
    }

    /**
     * Transient and unexpected polling failures return null (retry next run)
     * until the generation exceeds --hard-max-age, which bounds wallet holds.
     */
    private function unreachableData(Generation $generation, string $providerId, DateTimeInterface $outageCutoff): ?ProviderGenerationData
    {
        if ($generation->created_at === null || $generation->created_at->gt($outageCutoff)) {
            return null;
        }

        return $this->failedData($generation, $providerId, 'reconciliation_unreachable', 'The provider could not be reached before the maximum generation age.');
    }

    private function failedData(Generation $generation, string $providerId, string $errorCode, string $errorMessage): ProviderGenerationData
    {
        return new ProviderGenerationData(
            id: $providerId,
            status: GenerationStatus::Failed,
            rates: $generation->rates_snapshot,
            metadata: [],
            imageUrl: null,
            imageUrlExpiresAt: null,
            usage: null,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            createdAt: null,
            startedAt: null,
            completedAt: now()->toIso8601String(),
        );
    }
}
