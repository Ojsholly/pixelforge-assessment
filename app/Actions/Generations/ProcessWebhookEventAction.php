<?php

namespace App\Actions\Generations;

use App\Data\Pixelforge\ProviderGenerationData;
use App\Enums\WebhookEventType;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

/**
 * Applies one stored webhook event to its generation inside a single
 * transaction: the event row lock + processed_at guard make redelivery a
 * no-op, generation events resolve their row, hydrate the provider DTO,
 * and hand off to ApplyGenerationStateAction — whose own generation row
 * lock serializes against reconcile polls and other event deliveries.
 */
final readonly class ProcessWebhookEventAction
{
    public function __construct(private ApplyGenerationStateAction $applyState) {}

    public function execute(string $providerEventId): void
    {
        DB::transaction(function () use ($providerEventId): void {
            /** @var WebhookEvent $event */
            $event = WebhookEvent::query()
                ->where('provider_event_id', $providerEventId)
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
                    $this->applyState->execute($generation, $providerData);
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
