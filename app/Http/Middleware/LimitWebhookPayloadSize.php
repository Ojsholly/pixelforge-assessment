<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepended to the global middleware stack so oversized webhook bodies are
 * rejected on Content-Length before any later middleware — including the
 * framework's request transformers — can read and decode the JSON payload.
 * The route-level signature middleware keeps a byte backstop for chunked
 * or mislabeled deliveries that arrive without a trustworthy length.
 */
final class LimitWebhookPayloadSize
{
    /**
     * Real generation payloads are ~2 KB; 256 KB is generous headroom.
     */
    public const int MAX_PAYLOAD_BYTES = 262_144;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('webhooks/*') && $request->isMethod('POST')) {
            $contentLength = $request->header('Content-Length');

            if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
                return response()->json(['message' => 'Webhook payload too large.'], 413);
            }
        }

        return $next($request);
    }
}
