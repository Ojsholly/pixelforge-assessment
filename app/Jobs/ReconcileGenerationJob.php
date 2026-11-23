<?php

namespace App\Jobs;

use App\Actions\Generations\ReconcileGenerationAction;
use App\Models\Generation;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reconciles one stale generation against the provider. Unique per
 * generation id: a stale row picked up by two consecutive scheduled runs
 * produces at most one in-flight job; the generation row lock inside
 * ApplyGenerationStateAction serializes against live webhook arrivals.
 *
 * Runs on the `reconciliation` queue: provider polling can block for
 * seconds per job, so it must not sit ahead of webhook settlement work
 * on the default queue when a single worker drains `default` first.
 */
#[Backoff([30])]
#[Tries(2)]
final class ReconcileGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Generation $generation,
        public readonly DateTimeInterface $timeoutCutoff,
        public readonly DateTimeInterface $outageCutoff,
    ) {
        $this->onQueue('reconciliation');
    }

    public function uniqueId(): string
    {
        return 'reconcile-generation-'.$this->generation->id;
    }

    public function handle(ReconcileGenerationAction $reconcile): void
    {
        $reconcile->execute($this->generation, $this->timeoutCutoff, $this->outageCutoff);
    }
}
