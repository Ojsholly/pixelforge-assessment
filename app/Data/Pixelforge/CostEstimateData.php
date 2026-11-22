<?php

namespace App\Data\Pixelforge;

use App\Support\Money;

final readonly class CostEstimateData
{
    /**
     * @param  array{
     *     input_text_tokens: string,
     *     input_image_tokens: string,
     *     output_tokens: string,
     *     reasoning_tokens: string
     * }  $ratesSnapshot
     */
    public function __construct(
        public int $inputTextTokensMin,
        public int $inputTextTokensMax,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokensMin,
        public int $reasoningTokensMax,
        public int $providerMinNanodollars,
        public int $providerMaxNanodollars,
        public int $userMinNanodollars,
        public int $userMaxNanodollars,
        public array $ratesSnapshot,
    ) {}

    /**
     * @return array{
     *     input_text_min: int,
     *     input_text_max: int,
     *     input_image: int,
     *     output: int,
     *     reasoning_min: int,
     *     reasoning_max: int
     * }
     */
    public function estimatedTokensArray(): array
    {
        return [
            'input_text_min' => $this->inputTextTokensMin,
            'input_text_max' => $this->inputTextTokensMax,
            'input_image' => $this->inputImageTokens,
            'output' => $this->outputTokens,
            'reasoning_min' => $this->reasoningTokensMin,
            'reasoning_max' => $this->reasoningTokensMax,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tokens' => $this->estimatedTokensArray(),
            'provider_min_nanodollars' => $this->providerMinNanodollars,
            'provider_max_nanodollars' => $this->providerMaxNanodollars,
            'user_min_nanodollars' => $this->userMinNanodollars,
            'user_max_nanodollars' => $this->userMaxNanodollars,
            'user_min_usd' => Money::toUsdString($this->userMinNanodollars),
            'user_max_usd' => Money::toUsdString($this->userMaxNanodollars),
            'user_min_display' => Money::formatDisplay($this->userMinNanodollars),
            'user_max_display' => Money::formatDisplay($this->userMaxNanodollars),
            'rates_snapshot' => $this->ratesSnapshot,
        ];
    }
}
