<?php

use App\Exceptions\ProviderException;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitWebhookPayloadSize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First middleware to see every request: oversized webhook bodies are
        // rejected on Content-Length before TrimStrings/ConvertEmptyStringsToNull
        // (which iterate $request->all()) can decode the JSON payload.
        $middleware->prepend(LimitWebhookPayloadSize::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->validateCsrfTokens(except: ['webhooks/pixelforge']);

        $middleware->web(append: [
            AddSecurityHeaders::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*', 'webhooks/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ProviderException $exception, Request $request): JsonResponse|RedirectResponse {
            if ($request->is('webhooks/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->userMessage(),
                    'error_code' => $exception->errorCode,
                ], $exception->httpStatus());
            }

            return back()->withErrors([
                'provider' => $exception->userMessage(),
            ]);
        });
    })->create();
