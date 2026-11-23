<?php

namespace App\Http\Middleware;

use App\Contracts\ImageGenerationProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticity gate for provider webhook deliveries: enforces the payload
 * byte cap on the raw body (backstop for deliveries with no trustworthy
 * Content-Length — the global LimitWebhookPayloadSize handles declared
 * lengths before body parsing), then verifies the HMAC signature against
 * the configured provider before the request ever reaches the controller.
 */
class VerifyPixelforgeWebhookSignature
{
    public function __construct(private readonly ImageGenerationProvider $provider) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Content-Length was already checked by the globally prepended
        // LimitWebhookPayloadSize; strlen covers chunked or mislabeled
        // deliveries that arrive without a trustworthy length.
        $rawPayload = $request->getContent();

        if (strlen($rawPayload) > LimitWebhookPayloadSize::MAX_PAYLOAD_BYTES) {
            return response()->json(['message' => 'Webhook payload too large.'], 413);
        }

        if (! $this->provider->verifyWebhookSignature($rawPayload, $request->header('Pixelforge-Signature'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        return $next($request);
    }
}
