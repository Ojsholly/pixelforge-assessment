<?php

namespace App\Http\Middleware;

use App\Contracts\ImageGenerationProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticity gate for provider webhook deliveries: rejects oversized
 * bodies before they are read (Content-Length, then a byte backstop on
 * the raw payload), then verifies the HMAC signature against the
 * configured provider before the request ever reaches the controller.
 */
class VerifyPixelforgeWebhookSignature
{
    /**
     * Real generation payloads are ~2 KB; a cap rejects floods before the
     * body ever reaches the JSON decoder.
     */
    private const int MAX_PAYLOAD_BYTES = 262_144;

    public function __construct(private ImageGenerationProvider $provider) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->header('Content-Length');

        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'Webhook payload too large.'], 413);
        }

        $rawPayload = $request->getContent();

        if (strlen($rawPayload) > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'Webhook payload too large.'], 413);
        }

        if (! $this->provider->verifyWebhookSignature($rawPayload, $request->header('Pixelforge-Signature'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        return $next($request);
    }
}
