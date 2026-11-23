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
            try {
                $response = $this->publicClient()
                    ->retry(2, 200, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
                    ->get('/pricing');
            } catch (ConnectionException $connectionException) {
                throw new ProviderException(
                    message: 'Could not connect to Pixelforge pricing endpoint.',
                    statusCode: 503,
                    errorCode: 'connection_error',
                    previous: $connectionException,
                );
            }

            if ($response->failed()) {
                throw $this->toProviderException($response, 'Failed to fetch Pixelforge pricing.');
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                throw new ProviderException(
                    message: 'Malformed pricing payload: expected JSON object.',
                    statusCode: 502,
                    errorCode: 'malformed_response',
                );
            }

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

        try {
            $response = $this->authenticatedClient()
                ->withHeaders(['Idempotency-Key' => $input->idempotencyKey])
                ->retry(3, 200, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->status() === 503), throw: false)
                ->post('/generations', $body);
        } catch (ConnectionException $connectionException) {
            throw new ProviderException(
                message: 'Could not connect to Pixelforge generation endpoint.',
                statusCode: 503,
                errorCode: 'connection_error',
                previous: $connectionException,
            );
        }

        if ($response->failed()) {
            throw $this->toProviderException($response, 'Failed to create Pixelforge generation.');
        }

        return $this->hydrateGeneration($response);
    }

    public function getGeneration(string $providerId): ProviderGenerationData
    {
        try {
            $response = $this->authenticatedClient()
                ->retry(2, 200, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
                ->get("/generations/{$providerId}");
        } catch (ConnectionException $connectionException) {
            throw new ProviderException(
                message: 'Could not connect to Pixelforge generation endpoint.',
                statusCode: 503,
                errorCode: 'connection_error',
                previous: $connectionException,
            );
        }

        if ($response->failed()) {
            throw $this->toProviderException($response, 'Failed to fetch Pixelforge generation.');
        }

        return $this->hydrateGeneration($response);
    }

    public function getUsage(string $startDate, string $endDate): UsageReportData
    {
        try {
            $response = $this->authenticatedClient()
                ->retry(2, 200, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
                ->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]);
        } catch (ConnectionException $connectionException) {
            throw new ProviderException(
                message: 'Could not connect to Pixelforge usage endpoint.',
                statusCode: 503,
                errorCode: 'connection_error',
                previous: $connectionException,
            );
        }

        if ($response->failed()) {
            throw $this->toProviderException($response, 'Failed to fetch Pixelforge usage report.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ProviderException(
                message: 'Malformed usage payload: expected JSON object.',
                statusCode: 502,
                errorCode: 'malformed_response',
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            return UsageReportData::fromArray($payload);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new ProviderException(
                message: $invalidArgumentException->getMessage(),
                statusCode: 502,
                errorCode: 'malformed_response',
                previous: $invalidArgumentException,
            );
        }
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

    private function hydrateGeneration(Response $response): ProviderGenerationData
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ProviderException(
                message: 'Malformed generation payload: expected JSON object.',
                statusCode: 502,
                errorCode: 'malformed_response',
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            return ProviderGenerationData::fromArray($payload);
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
