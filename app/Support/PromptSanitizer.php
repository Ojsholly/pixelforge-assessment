<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class PromptSanitizer
{
    public const int MAX_PROMPT_LENGTH = 4000;

    /**
     * Sanitize an untrusted user prompt while preserving Pixelforge test keywords
     * (e.g. [fail], [slow], [duplicate]) and valid Unicode text.
     */
    public static function sanitize(string $prompt): string
    {
        if (! mb_check_encoding($prompt, 'UTF-8')) {
            throw new InvalidArgumentException('Prompt must be valid UTF-8.');
        }

        // Normalize line endings and strip ASCII control characters except \n and \t.
        $normalized = Str::replace(["\r\n", "\r"], "\n", $prompt);
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $normalized);

        if ($stripped === null) {
            throw new InvalidArgumentException('Failed to sanitize prompt.');
        }

        $trimmed = trim($stripped);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Prompt cannot be empty.');
        }

        if (Str::length($trimmed) > self::MAX_PROMPT_LENGTH) {
            throw new InvalidArgumentException('Prompt exceeds maximum length of '.self::MAX_PROMPT_LENGTH.' characters.');
        }

        return $trimmed;
    }
}
