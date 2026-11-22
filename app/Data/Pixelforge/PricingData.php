<?php

namespace App\Data\Pixelforge;

final readonly class PricingData
{
    /**
     * @param  array{
     *     input_text_tokens: string,
     *     input_image_tokens: string,
     *     output_tokens: string,
     *     reasoning_tokens: string
     * }  $rates
     * @param  array<string, array<string, int>>  $outputTokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens
     */
    public function __construct(
        public int $charactersPerTextToken,
        public float $textTokenVariance,
        public int $perReferenceImageTokens,
        public array $rates,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} $rates */
        $rates = $payload['rates'];
        /** @var array{characters_per_text_token: int, text_token_variance: float|int, per_reference_image: int, output: array<string, array<string, int>>, reasoning: array<string, array{min: int, max: int}>} $tokens */
        $tokens = $payload['tokens'];

        return new self(
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVariance: (float) $tokens['text_token_variance'],
            perReferenceImageTokens: (int) $tokens['per_reference_image'],
            rates: $rates,
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }
}
