<?php

namespace App\Services\Pixelforge;

use App\Contracts\ImageGenerationProvider;
use App\Data\Pixelforge\CreateGenerationInput;
use App\Data\Pixelforge\PricingData;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Data\Pixelforge\UsageReportData;
use App\Exceptions\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class PixelforgeProvider implements ImageGenerationProvider
{
    public const string PRICING_CACHE_KEY = 'pixelforge:pricing';

    public function getPricing(): PricingData
    {
        // Cache the wire payload, not the DTO: serializable_classes=false in
        // cache config means objects unserialize to __PHP_Incomplete_Class.
        /** @var array<string, mixed> $payload */
        $payload = Cache::remember(self::PRICING_CACHE_KEY, now()->addMinutes(5), function (): array {
            $response = $this->send(
                client: $this->publicClient(),
                retries: 2,
                shouldRetry: self::isTransientError(...),
                connectionMessage: 'Could not connect to Pixelforge pricing endpoint.',
                failureMessage: 'Failed to fetch Pixelforge pricing.',
                request: fn (PendingRequest $client): Response => $client->get('/pricing'),
            );

            $payload = $this->objectPayload($response, 'pricing');

            $this->pricingFromPayload($payload);

            return $payload;
        });

        return $this->pricingFromPayload($payload);
    }

    public function createGeneration(CreateGenerationInput $input): ProviderGenerationData
    {
        $body = [
            'prompt' => $input->prompt,
            'size' => $input->size->value,
            'quality' => $input->quality->value,
        ];

        if ($input->referenceImages !== []) {
            $body['reference_images'] = $input->referenceImages;
        }

        if ($input->metadata !== []) {
            $body['metadata'] = $input->metadata;
        }

        $response = $this->send(
            client: $this->authenticatedClient()->withHeaders(['Idempotency-Key' => $input->idempotencyKey]),
            retries: 3,
            shouldRetry: self::isOverloadedError(...),
            connectionMessage: 'Could not connect to Pixelforge generation endpoint.',
            failureMessage: 'Failed to create Pixelforge generation.',
            request: fn (PendingRequest $client): Response => $client->post('/generations', $body),
        );

        return $this->hydrateGeneration($response);
    }

    public function getGeneration(string $providerId): ProviderGenerationData
    {
        $response = $this->send(
            client: $this->authenticatedClient(),
            retries: 2,
            shouldRetry: self::isTransientError(...),
            connectionMessage: 'Could not connect to Pixelforge generation endpoint.',
            failureMessage: 'Failed to fetch Pixelforge generation.',
            request: fn (PendingRequest $client): Response => $client->get("/generations/{$providerId}"),
        );

        return $this->hydrateGeneration($response);
    }

    public function getUsage(string $startDate, string $endDate): UsageReportData
    {
        $response = $this->send(
            client: $this->authenticatedClient(),
            retries: 2,
            shouldRetry: self::isTransientError(...),
            connectionMessage: 'Could not connect to Pixelforge usage endpoint.',
            failureMessage: 'Failed to fetch Pixelforge usage report.',
            request: fn (PendingRequest $client): Response => $client->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]),
        );

        return $this->hydrate($response, UsageReportData::fromArray(...), 'usage');
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, int $toleranceSeconds = 300): bool
    {
        $secret = (string) config('services.pixelforge.webhook_secret', '');

        if ($secret === '' || $signatureHeader === null || trim($signatureHeader) === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            $segments = explode('=', trim($part), 2);
            if (count($segments) !== 2) {
                continue;
            }

            [$key, $value] = $segments;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $secret);

        return array_any($signatures, fn (string $signature): bool => hash_equals($expected, $signature));
    }

    /**
     * Execute an HTTP call with retry + uniform exception translation:
     * ConnectionException → 503, any failed response → the provider error.
     *
     * @param  callable(PendingRequest): Response  $request
     * @param  callable(Throwable): bool  $shouldRetry
     */
    private function send(
        PendingRequest $client,
        int $retries,
        callable $shouldRetry,
        string $connectionMessage,
        string $failureMessage,
        callable $request,
    ): Response {
        try {
            $response = $request($client->retry($retries, 200, $shouldRetry, throw: false));
        } catch (ConnectionException $connectionException) {
            throw new ProviderException(
                message: $connectionMessage,
                statusCode: 503,
                errorCode: 'connection_error',
                previous: $connectionException,
            );
        }

        if ($response->failed()) {
            throw $this->toProviderException($response, $failureMessage);
        }

        return $response;
    }

    /**
     * Retry every connection failure and any 5xx response.
     */
    private static function isTransientError(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
    }

    /**
     * Retry connection failures and only 503s — a 429 means the caller must
     * slow down, not retry.
     */
    private static function isOverloadedError(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->status() === 503);
    }

    private function publicClient(): PendingRequest
    {
        /** @var string $baseUrl */
        $baseUrl = config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->timeout(15);
    }

    private function authenticatedClient(): PendingRequest
    {
        $apiKey = (string) config('services.pixelforge.api_key', '');

        if ($apiKey === '') {
            throw new ProviderException(
                message: 'Pixelforge API key is not configured.',
                statusCode: 500,
                errorCode: 'missing_api_key',
            );
        }

        return $this->publicClient()->withToken($apiKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function objectPayload(Response $response, string $context): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ProviderException(
                message: "Malformed {$context} payload: expected JSON object.",
                statusCode: 502,
                errorCode: 'malformed_response',
            );
        }

        return $payload;
    }

    /**
     * Decode the response JSON and hydrate it through a DTO factory, mapping
     * malformed payloads to a 502 ProviderException.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $hydrate
     * @return T
     */
    private function hydrate(Response $response, callable $hydrate, string $context): mixed
    {
        try {
            return $hydrate($this->objectPayload($response, $context));
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ProviderException(
                message: $invalidArgumentException->getMessage(),
                statusCode: 502,
                errorCode: 'malformed_response',
                previous: $invalidArgumentException,
            );
        }
    }

    private function hydrateGeneration(Response $response): ProviderGenerationData
    {
        return $this->hydrate($response, ProviderGenerationData::fromArray(...), 'generation');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pricingFromPayload(array $payload): PricingData
    {
        try {
            return PricingData::fromArray($payload);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ProviderException(
                message: $invalidArgumentException->getMessage(),
                statusCode: 502,
                errorCode: 'malformed_response',
                previous: $invalidArgumentException,
            );
        }
    }

    private function toProviderException(Response $response, string $fallbackMessage): ProviderException
    {
        $error = $response->json('error');
        $errorCode = is_array($error) && isset($error['code']) ? (string) $error['code'] : null;
        $message = is_array($error) && isset($error['message']) ? (string) $error['message'] : $fallbackMessage;
        $retryAfter = $response->header('Retry-After');

        return new ProviderException(
            message: $message,
            statusCode: $response->status(),
            errorCode: $errorCode,
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
