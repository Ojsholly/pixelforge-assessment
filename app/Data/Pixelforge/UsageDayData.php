<?php

namespace App\Data\Pixelforge;

use App\Support\Money;

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
     * @param  array<string, int|string>  $payload
     */
    public static function fromArray(array $payload): self
    {
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
