<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\ReconcileGenerationJob;
use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Event;

#[Description('Poll Pixelforge for stale queued or processing generations whose webhooks may have been missed.')]
#[Signature('generations:reconcile-stale {--minutes=2 : Minimum age in minutes before reconciling} {--max-age=60 : Maximum age in minutes before timing out pending generations} {--hard-max-age=1440 : Maximum age in minutes before failing generations while the provider cannot be reached}')]
class ReconcileStaleGenerationsCommand extends Command
{
    /**
     * Selects stale generations and dispatches one ReconcileGenerationJob
     * each through the normal dispatch path — batches push via bulk() and
     * bypass ShouldBeUnique, so individual dispatches are what actually
     * dedupes a generation that is still queued at the next scheduled run.
     * Every job carries the cutoff snapshot the selection ran against.
     */
    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $maxAge = max($minutes, (int) $this->option('max-age'));
        $hardMaxAge = max($maxAge, (int) $this->option('hard-max-age'));
        $cutoff = now()->subMinutes($minutes);
        $timeoutCutoff = now()->subMinutes($maxAge);
        $outageCutoff = now()->subMinutes($hardMaxAge);

        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale generations to reconcile.');

            return self::SUCCESS;
        }

        // The unique check runs in PendingDispatch::__destruct, so there is
        // no return value to count on — UniqueJobSkipped is the signal when
        // a generation is already queued or being reconciled.
        $skipped = 0;
        Event::listen(UniqueJobSkipped::class, function () use (&$skipped): void {
            $skipped++;
        });

        foreach ($stale as $generation) {
            ReconcileGenerationJob::dispatch($generation, $timeoutCutoff, $outageCutoff);
        }

        $dispatched = $stale->count() - $skipped;

        $this->info("Dispatched {$dispatched} stale-generation reconciliation job(s).");

        return self::SUCCESS;
    }
}
