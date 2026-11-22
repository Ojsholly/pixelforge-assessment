<?php

namespace App\Data\Pixelforge;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use InvalidArgumentException;

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
        if (! is_array($payload['rates'] ?? null) || ! is_array($payload['tokens'] ?? null)) {
            throw new InvalidArgumentException('Malformed pricing payload: missing rates or tokens.');
        }

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $rateKey) {
            if (! isset($payload['rates'][$rateKey])) {
                throw new InvalidArgumentException("Malformed pricing payload: missing rate [{$rateKey}].");
            }
        }

        foreach (['characters_per_text_token', 'text_token_variance', 'per_reference_image', 'output', 'reasoning'] as $tokenKey) {
            if (! isset($payload['tokens'][$tokenKey])) {
                throw new InvalidArgumentException("Malformed pricing payload: missing token config [{$tokenKey}].");
            }
        }

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

    public function outputTokensFor(ImageQuality $quality, ImageSize $size): int
    {
        $tokens = $this->outputTokens[$quality->value][$size->value] ?? null;

        if ($tokens === null) {
            throw new InvalidArgumentException("Missing output token count for [{$quality->value}] / [{$size->value}].");
        }

        return $tokens;
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRangeFor(ImageQuality $quality): array
    {
        $range = $this->reasoningTokens[$quality->value] ?? null;

        if ($range === null) {
            throw new InvalidArgumentException("Missing reasoning token range for [{$quality->value}].");
        }

        return $range;
    }
}
