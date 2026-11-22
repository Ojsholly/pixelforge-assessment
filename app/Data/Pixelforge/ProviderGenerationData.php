<?php

namespace App\Data\Pixelforge;

use App\Enums\GenerationStatus;
use InvalidArgumentException;

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
            imageUrlExpiresAt: isset($payload['image_url_expires_at']) ? (string) $payload['image_url_expires_at'] : null,
            usage: $usage !== null ? ProviderUsageData::fromArray($usage) : null,
            errorCode: isset($error['code']) ? (string) $error['code'] : null,
            errorMessage: isset($error['message']) ? (string) $error['message'] : null,
            createdAt: isset($payload['created_at']) ? (string) $payload['created_at'] : null,
            startedAt: isset($payload['started_at']) ? (string) $payload['started_at'] : null,
            completedAt: isset($payload['completed_at']) ? (string) $payload['completed_at'] : null,
        );
    }
}
