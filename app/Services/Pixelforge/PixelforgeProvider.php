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
use LogicException;
use Throwable;

class PixelforgeProvider implements ImageGenerationProvider
{
    public const string PRICING_CACHE_KEY = 'pixelforge:pricing';

    public function getPricing(): PricingData
    {
        return Cache::remember(self::PRICING_CACHE_KEY, now()->addMinutes(5), function (): PricingData {
            $response = $this->client()
                ->retry(2, 200, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
                ->get('/pricing');

            if ($response->failed()) {
                throw $this->toProviderException($response, 'Failed to fetch Pixelforge pricing.');
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->json() ?? [];

            return PricingData::fromArray($payload);
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

    private function client(): PendingRequest
    {
        /** @var string $baseUrl */
        $baseUrl = config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1');
        $apiKey = (string) config('services.pixelforge.api_key', '');

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withToken($apiKey)
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
