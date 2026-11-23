<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists the signature-verified event (idempotency/audit) and hands
 * state application to ProcessWebhookEvent so the endpoint stays well
 * inside the provider's 10s delivery budget. Size and signature checks
 * live in VerifyPixelforgeWebhookSignature.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['type'] ?? null)) {
            return response()->json(['message' => 'Malformed webhook payload.'], 400);
        }

        $eventId = $payload['id'];
        $eventType = $payload['type'];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $object = is_array($data['object'] ?? null) ? $data['object'] : null;
        $providerGenerationId = is_array($object) && is_string($object['id'] ?? null) ? $object['id'] : null;
        $deliveryAttempt = max(1, (int) ($request->header('Pixelforge-Delivery-Attempt') ?? 1));

        if (Str::length($eventId) > 64 || ($providerGenerationId !== null && Str::length($providerGenerationId) > 64)) {
            return response()->json(['message' => 'Malformed webhook identifier.'], 400);
        }

        $now = now();

        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'provider' => (string) config('services.image_provider.driver', 'pixelforge'),
            'provider_event_id' => $eventId,
            'event_type' => $eventType,
            'provider_generation_id' => $providerGenerationId,
            'delivery_attempt' => $deliveryAttempt,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'processed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            // Duplicate delivery: keep the highest observed attempt for
            // observability (the job's unique lock may dedupe dispatch).
            DB::table('webhook_events')
                ->where('provider_event_id', $eventId)
                ->where('delivery_attempt', '<', $deliveryAttempt)
                ->update(['delivery_attempt' => $deliveryAttempt, 'updated_at' => $now]);
        }

        // ShouldBeUnique drops redeliveries that arrive while a copy is
        // queued or running; the processed_at guard inside the job covers
        // redelivery after completion.
        ProcessWebhookEvent::dispatch($eventId);

        return response()->noContent();
    }
}
