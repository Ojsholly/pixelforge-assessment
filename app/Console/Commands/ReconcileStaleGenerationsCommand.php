<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyGenerationStateAction;
use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Description('Poll Pixelforge for stale queued or processing generations whose webhooks may have been missed.')]
#[Signature('generations:reconcile-stale {--minutes=2 : Minimum age in minutes before reconciling} {--max-age=60 : Maximum age in minutes before timing out stale generations}')]
class ReconcileStaleGenerationsCommand extends Command
{
    public function handle(
        ImageGenerationProvider $provider,
        ApplyGenerationStateAction $applyState,
    ): int {
        $minutes = max(1, (int) $this->option('minutes'));
        $maxAge = max($minutes, (int) $this->option('max-age'));
        $cutoff = now()->subMinutes($minutes);
        $timeoutCutoff = now()->subMinutes($maxAge);

        $reconciled = 0;

        Generation::query()
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function (Collection $generations) use ($provider, $applyState, $timeoutCutoff, &$reconciled): void {
                /** @var Generation $generation */
                foreach ($generations as $generation) {
                    $providerId = $generation->provider_id;
                    if ($providerId === null) {
                        continue;
                    }

                    $previousStatus = $generation->status->status();

                    $result = rescue(
                        fn (): Generation => $applyState->execute($generation, $provider->getGeneration($providerId)),
                        report: true,
                    );

                    if ($result === null && $generation->created_at !== null && $generation->created_at->lte($timeoutCutoff)) {
                        $result = $applyState->execute($generation, new ProviderGenerationData(
                            id: $providerId,
                            status: GenerationStatus::Failed,
                            rates: $generation->rates_snapshot,
                            metadata: [],
                            imageUrl: null,
                            imageUrlExpiresAt: null,
                            usage: null,
                            errorCode: 'reconciliation_timeout',
                            errorMessage: 'Generation timed out awaiting provider completion.',
                            createdAt: null,
                            startedAt: null,
                            completedAt: now()->toIso8601String(),
                        ));
                    }

                    if ($result instanceof Generation && $result->status->status() !== $previousStatus) {
                        $reconciled++;
                    }
                }
            });

        $this->info("Reconciled {$reconciled} stale generation(s).");

        return self::SUCCESS;
    }
}
