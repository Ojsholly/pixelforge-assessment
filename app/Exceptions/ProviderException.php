<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'status_code' => $this->statusCode,
            'error_code' => $this->errorCode,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }

    public function httpStatus(): int
    {
        return match (true) {
            $this->statusCode >= 400 && $this->statusCode < 500 => $this->statusCode,
            default => 503,
        };
    }

    /**
     * Whether the provider definitively rejected the request before a
     * generation could exist: 4xx validation/policy/idempotency failures and
     * explicit `overloaded` rejections. Connection errors and other 5xx are
     * ambiguous — the request may have landed — so callers should leave the
     * generation pending for webhook/reconcile recovery instead of failing it.
     */
    public function isDefinitiveRejection(): bool
    {
        return ($this->statusCode >= 400 && $this->statusCode < 500)
            || $this->errorCode === 'overloaded';
    }

    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Your prompt or reference image was rejected by the content policy.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded' => 'Rate limit reached. Please wait a moment and try again.',
            'overloaded' => 'The image provider is temporarily overloaded. Please try again shortly.',
            default => 'The image provider is temporarily unavailable. Please try again.',
        };
    }
}
