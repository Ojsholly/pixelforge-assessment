<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\ImageGenerationProvider;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verifies the signature, persists the raw event for idempotency/audit,
 * and hands state application to ProcessWebhookEvent so the endpoint
 * stays well inside the provider's 10s delivery budget.
 */
class PixelforgeWebhookController extends Controller
{
    /**
     * Real generation payloads are ~2 KB; a cap rejects floods before the
     * body ever reaches the JSON decoder.
     */
    private const int MAX_PAYLOAD_BYTES = 262_144;

    public function __invoke(Request $request, ImageGenerationProvider $provider): Response|JsonResponse
    {
        // Reject oversized bodies before the raw payload is even read into
        // memory — Content-Length is cheap; the byte count is the backstop.
        $contentLength = $request->header('Content-Length');

        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'Webhook payload too large.'], 413);
        }

        $rawPayload = $request->getContent();

        if (! $provider->verifyWebhookSignature($rawPayload, $request->header('Pixelforge-Signature'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        if (strlen($rawPayload) > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'Webhook payload too large.'], 413);
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
