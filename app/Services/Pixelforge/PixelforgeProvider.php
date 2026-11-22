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
use LogicException;
use Throwable;

final class PixelforgeProvider implements ImageGenerationProvider
{
    public const string PRICING_CACHE_KEY = 'pixelforge:pricing';

    public function getPricing(): PricingData
    {
        return Cache::remember(self::PRICING_CACHE_KEY, now()->addMinutes(5), function (): PricingData {
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

            try {
                /** @var array<string, mixed> $payload */
                return PricingData::fromArray($payload);
            } catch (InvalidArgumentException $invalidArgumentException) {
                throw new ProviderException(
                    message: $invalidArgumentException->getMessage(),
                    statusCode: 502,
                    errorCode: 'malformed_response',
                    previous: $invalidArgumentException,
                );
            }
        });
    }

    public function createGeneration(CreateGenerationInput $input): ProviderGenerationData
    {
        throw new LogicException('createGeneration() will be implemented in Epic 03.');
    }

    public function getGeneration(string $providerId): ProviderGenerationData
    {
        throw new LogicException('getGeneration() will be implemented in Epic 03.');
    }

    public function getUsage(string $startDate, string $endDate): UsageReportData
    {
        throw new LogicException('getUsage() will be implemented in Epic 07.');
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, int $toleranceSeconds = 300): bool
    {
        throw new LogicException('verifyWebhookSignature() will be implemented in Epic 04.');
    }

    private function publicClient(): PendingRequest
    {
        /** @var string $baseUrl */
        $baseUrl = config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->timeout(15);
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
