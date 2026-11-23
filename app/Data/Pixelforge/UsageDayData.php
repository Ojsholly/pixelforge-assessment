<?php

namespace App\Data\Pixelforge;

use App\Support\Money;
use InvalidArgumentException;

final readonly class UsageDayData
{
    public function __construct(
        public string $date,
        public int $generations,
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public string $costUsd,
        public int $costNanodollars,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        foreach (['date', 'generations', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens', 'cost_usd'] as $key) {
            if (! isset($payload[$key]) || ! is_scalar($payload[$key])) {
                throw new InvalidArgumentException("Malformed usage day payload: missing or invalid [{$key}].");
            }
        }

        $costUsd = (string) $payload['cost_usd'];

        return new self(
            date: (string) $payload['date'],
            generations: (int) $payload['generations'],
            inputTextTokens: (int) $payload['input_text_tokens'],
            inputImageTokens: (int) $payload['input_image_tokens'],
            outputTokens: (int) $payload['output_tokens'],
            reasoningTokens: (int) $payload['reasoning_tokens'],
            costUsd: $costUsd,
            costNanodollars: Money::fromUsdString($costUsd),
        );
    }
}
