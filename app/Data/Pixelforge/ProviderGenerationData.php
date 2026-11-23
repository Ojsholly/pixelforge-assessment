<?php

namespace App\Data\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class ProviderGenerationData
{
    /**
     * @param  array{
     *     input_text_tokens: string,
     *     input_image_tokens: string,
     *     output_tokens: string,
     *     reasoning_tokens: string
     * }  $rates
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public array $rates,
        public array $metadata,
        public ?string $imageUrl,
        public ?string $imageUrlExpiresAt,
        public ?ProviderUsageData $usage,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?string $createdAt,
        public ?string $startedAt,
        public ?string $completedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        if (! isset($payload['id'], $payload['status']) || ! is_array($payload['rates'] ?? null)) {
            throw new InvalidArgumentException('Malformed provider generation payload: missing id, status, or rates.');
        }

        if (! is_scalar($payload['id']) || ! is_scalar($payload['status'])) {
            throw new InvalidArgumentException('Malformed provider generation payload: invalid id or status.');
        }

        $statusString = (string) $payload['status'];
        $status = GenerationStatus::tryFrom($statusString);

        if ($status === null) {
            throw new InvalidArgumentException("Unsupported provider generation status: [{$statusString}].");
        }

        if (isset($payload['image_url']) && ! is_scalar($payload['image_url'])) {
            throw new InvalidArgumentException('Malformed provider generation payload: invalid image_url.');
        }

        // A succeeded generation without usage or image_url is an incomplete
        // payload: settling it would charge $0, release the full hold, and the
        // terminal state would then swallow the later complete payload.
        if ($status === GenerationStatus::Succeeded && (! is_array($payload['usage'] ?? null) || empty($payload['image_url']))) {
            throw new InvalidArgumentException('Malformed provider generation payload: succeeded status requires usage and image_url.');
        }

        /** @var array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} $rates */
        $rates = $payload['rates'];
        /** @var array<string, string> $metadata */
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        /** @var array<string, int|string>|null $usage */
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : null;
        /** @var array{code?: string, message?: string}|null $error */
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : null;

        return new self(
            id: (string) $payload['id'],
            status: $status,
            rates: $rates,
            metadata: $metadata,
            imageUrl: isset($payload['image_url']) ? (string) $payload['image_url'] : null,
            imageUrlExpiresAt: self::timestamp($payload['image_url_expires_at'] ?? null),
            usage: $usage !== null ? ProviderUsageData::fromArray($usage) : null,
            errorCode: self::optionalString($error['code'] ?? null),
            errorMessage: self::optionalString($error['message'] ?? null),
            createdAt: self::timestamp($payload['created_at'] ?? null),
            startedAt: self::timestamp($payload['started_at'] ?? null),
            completedAt: self::timestamp($payload['completed_at'] ?? null),
        );
    }

    /**
     * Provider timestamps are informational only; unparseable values are
     * discarded so a malformed optional timestamp can neither poison a webhook
     * event mid-transaction nor fail an already-accepted generation.
     */
    private static function timestamp(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
