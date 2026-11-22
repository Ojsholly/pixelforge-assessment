<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyGenerationStateAction;
use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Enums\GenerationStatus;
use App\Exceptions\ProviderException;
use App\Models\Generation;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Description('Poll Pixelforge for stale queued or processing generations whose webhooks may have been missed.')]
#[Signature('generations:reconcile-stale {--minutes=2 : Minimum age in minutes before reconciling} {--max-age=60 : Maximum age in minutes before timing out pending generations} {--hard-max-age=1440 : Maximum age in minutes before failing generations while the provider cannot be reached}')]
class ReconcileStaleGenerationsCommand extends Command
{
    public function handle(
        ImageGenerationProvider $provider,
        ApplyGenerationStateAction $applyState,
    ): int {
        $minutes = max(1, (int) $this->option('minutes'));
        $maxAge = max($minutes, (int) $this->option('max-age'));
        $hardMaxAge = max($maxAge, (int) $this->option('hard-max-age'));
        $cutoff = now()->subMinutes($minutes);
        $timeoutCutoff = now()->subMinutes($maxAge);
        $outageCutoff = now()->subMinutes($hardMaxAge);

        $reconciled = 0;

        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function (Collection $generations) use ($provider, $applyState, $timeoutCutoff, $outageCutoff, &$reconciled): void {
                /** @var Generation $generation */
                foreach ($generations as $generation) {
                    $providerId = $generation->provider_id;
                    if ($providerId === null) {
                        continue;
                    }

                    $previousStatus = $generation->status->status();

                    $providerData = $this->resolveProviderData($provider, $generation, $providerId, $timeoutCutoff, $outageCutoff);

                    if (! $providerData instanceof ProviderGenerationData) {
                        continue;
                    }

                    $result = rescue(
                        fn (): Generation => $applyState->execute($generation, $providerData),
                        report: true,
                    );

                    if ($result instanceof Generation && $result->status->status() !== $previousStatus) {
                        $reconciled++;
                    }
                }
            });

        $this->info("Reconciled {$reconciled} stale generation(s).");

        return self::SUCCESS;
    }

    /**
     * Poll the provider for the generation's status. Definitive outcomes (404, or
     * still-pending past --max-age) become a synthetic failed payload; transient
     * failures return null so the generation is retried on the next run unless it
     * has exceeded --hard-max-age.
     */
    private function resolveProviderData(
        ImageGenerationProvider $provider,
        Generation $generation,
        string $providerId,
        DateTimeInterface $timeoutCutoff,
        DateTimeInterface $outageCutoff,
    ): ?ProviderGenerationData {
        try {
            $providerData = $provider->getGeneration($providerId);
        } catch (ProviderException $exception) {
            report($exception);

            if ($exception->statusCode === 404) {
                return $this->failedData($generation, $providerId, 'reconciliation_not_found', 'The provider reports this generation no longer exists.');
            }

            if ($generation->created_at !== null && $generation->created_at->lte($outageCutoff)) {
                return $this->failedData($generation, $providerId, 'reconciliation_unreachable', 'The provider could not be reached before the maximum generation age.');
            }

            return null;
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
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
