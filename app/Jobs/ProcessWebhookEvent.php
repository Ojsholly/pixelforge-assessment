<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyGenerationStateAction;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Enums\WebhookEventType;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Applies one stored webhook event to its generation. The controller has
 * already signature-verified and persisted the event; this job owns the
 * slower state-machine + wallet work so the endpoint answers well inside
 * the provider's 10s delivery budget under load.
 *
 * ShouldBeUnique keyed on the provider event id: redeliveries that arrive
 * while a copy is queued or running are dropped at dispatch, and the
 * processed_at guard under the row lock covers the post-completion window.
 */
#[Backoff([10, 60])]
#[Tries(3)]
final class ProcessWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $providerEventId) {}

    public function uniqueId(): string
    {
        return $this->providerEventId;
    }

    public function handle(ApplyGenerationStateAction $applyState): void
    {
        DB::transaction(function () use ($applyState): void {
            /** @var WebhookEvent $event */
            $event = WebhookEvent::query()
                ->where('provider_event_id', $this->providerEventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->processed_at !== null) {
                return;
            }

            /** @var array<string, mixed> $payload */
            $payload = $event->payload;
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            /** @var array<mixed, mixed>|null $object */
            $object = is_array($data['object'] ?? null) ? $data['object'] : null;
            $providerGenerationId = is_array($object) && is_string($object['id'] ?? null) ? $object['id'] : null;
            $eventType = (string) ($event->event_type ?? '');

            $resolvedType = WebhookEventType::tryFrom($eventType);
            $isGenerationEvent = in_array($resolvedType, [
                WebhookEventType::GenerationProcessing,
                WebhookEventType::GenerationSucceeded,
                WebhookEventType::GenerationFailed,
            ], true);

            if (! $isGenerationEvent || $object === null) {
                $event->processed_at = now();
                $event->save();

                return;
            }

            $generation = $this->findGeneration($providerGenerationId, $object);

            if ($generation instanceof Generation) {
                /** @var array<string, mixed> $object */
                $providerData = rescue(
                    fn (): ProviderGenerationData => ProviderGenerationData::fromArray($object),
                    report: true,
                );

                if ($providerData instanceof ProviderGenerationData) {
                    $applyState->execute($generation, $providerData);
                }
            }

            $event->processed_at = now();
            $event->save();
        });
    }

    /**
     * @param  array<mixed, mixed>  $object
     */
    private function findGeneration(?string $providerGenerationId, array $object): ?Generation
    {
        if ($providerGenerationId !== null) {
            $byProvider = Generation::query()->where('provider_id', $providerGenerationId)->first();

            if ($byProvider instanceof Generation) {
                return $byProvider;
            }
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $localId = $metadata['local_generation_id'] ?? null;

        // The metadata fallback may only claim a generation that was never
        // bound to a provider id — otherwise a stale event could attach its
        // result to an unrelated row that recycled the local id.
        if ((is_int($localId) || (is_string($localId) && ctype_digit($localId))) && (int) $localId > 0) {
            return Generation::query()
                ->whereKey((int) $localId)
                ->whereNull('provider_id')
                ->first();
        }

        return null;
    }
}
