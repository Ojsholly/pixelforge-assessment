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
}
