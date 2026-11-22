<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Generations\ApplyGenerationStateAction;
use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ImageGenerationProvider $provider,
        ApplyGenerationStateAction $applyState,
    ): Response|JsonResponse {
        $rawPayload = $request->getContent();

        if (! $provider->verifyWebhookSignature($rawPayload, $request->header('Pixelforge-Signature'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        $payload = json_decode($rawPayload, true);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            return response()->json(['message' => 'Malformed webhook payload.'], 400);
        }

        $eventId = $payload['id'];
        $eventType = $payload['type'];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $object = is_array($data['object'] ?? null) ? $data['object'] : null;
        $providerGenerationId = is_array($object) && is_string($object['id'] ?? null) ? $object['id'] : null;
        $deliveryAttempt = max(1, (int) ($request->header('Pixelforge-Delivery-Attempt') ?? 1));

        if (mb_strlen($eventId, 'UTF-8') > 64 || ($providerGenerationId !== null && mb_strlen($providerGenerationId, 'UTF-8') > 64)) {
            return response()->json(['message' => 'Malformed webhook identifier.'], 400);
        }

        $now = now();

        DB::table('webhook_events')->insertOrIgnore([
            'provider_event_id' => $eventId,
            'event_type' => $eventType,
            'provider_generation_id' => $providerGenerationId,
            'delivery_attempt' => $deliveryAttempt,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'processed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::transaction(function () use ($eventId, $eventType, $object, $providerGenerationId, $deliveryAttempt, $applyState): void {
            /** @var WebhookEvent $event */
            $event = WebhookEvent::query()
                ->where('provider_event_id', $eventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->processed_at !== null) {
                return;
            }

            $event->delivery_attempt = max($event->delivery_attempt, $deliveryAttempt);

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

        return response()->noContent();
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

        if ((is_int($localId) || (is_string($localId) && ctype_digit($localId))) && (int) $localId > 0) {
            return Generation::query()->find((int) $localId);
        }

        return null;
    }
}
