<?php

namespace App\Jobs;

use App\Actions\Generations\ProcessWebhookEventAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Applies one stored webhook event to its generation. The controller has
 * already signature-verified and persisted the event; this job owns the
 * slower state-machine + wallet work so the endpoint answers well inside
 * the provider's 10s delivery budget under load.
 *
 * ShouldBeUnique keyed on the provider event id: redeliveries that arrive
 * while a copy is queued or running are dropped at dispatch, and the
 * processed_at guard under the row lock covers the post-completion window.
 * uniqueFor bounds a lock orphaned between acquisition and queue insert —
 * a later redelivery re-dispatches once it expires.
 */
#[Backoff([10, 60])]
#[Tries(3)]
#[UniqueFor(3600)]
final class ProcessWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $providerEventId) {}

    public function uniqueId(): string
    {
        return $this->providerEventId;
    }

    public function handle(ProcessWebhookEventAction $process): void
    {
        $process->execute($this->providerEventId);
    }
}
