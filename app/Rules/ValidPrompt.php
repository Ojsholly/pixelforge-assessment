<?php

namespace App\Rules;

use App\Support\PromptSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidPrompt implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $fail('The :attribute must be valid UTF-8 text.');

            return;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $value);
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $normalized);
        $trimmed = $stripped !== null ? trim($stripped) : '';

        if ($trimmed === '') {
            $fail('The :attribute cannot be empty.');

            return;
        }

        if (mb_strlen($trimmed, 'UTF-8') > PromptSanitizer::MAX_PROMPT_LENGTH) {
            $fail('The :attribute may not exceed '.PromptSanitizer::MAX_PROMPT_LENGTH.' characters.');
        }
    }
}
