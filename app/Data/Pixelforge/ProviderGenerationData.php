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

        $statusString = (string) $payload['status'];
        $status = GenerationStatus::tryFrom($statusString);

        if ($status === null) {
            throw new InvalidArgumentException("Unsupported provider generation status: [{$statusString}].");
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
            imageUrlExpiresAt: self::timestamp($payload['image_url_expires_at'] ?? null, 'image_url_expires_at'),
            usage: $usage !== null ? ProviderUsageData::fromArray($usage) : null,
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            errorMessage: isset($error['message']) ? (string) $error['message'] : null,
            createdAt: self::timestamp($payload['created_at'] ?? null, 'created_at'),
            startedAt: self::timestamp($payload['started_at'] ?? null, 'started_at'),
            completedAt: self::timestamp($payload['completed_at'] ?? null, 'completed_at'),
        );
    }

    /**
     * Timestamp fields are parsed inside ApplyGenerationStateAction, so validate
     * parseability here where callers already handle InvalidArgumentException.
     */
    private static function timestamp(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException("Malformed provider generation payload: invalid {$key} timestamp.");
        }

        try {
            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            throw new InvalidArgumentException("Malformed provider generation payload: unparseable {$key} timestamp.");
        }
    }
}
