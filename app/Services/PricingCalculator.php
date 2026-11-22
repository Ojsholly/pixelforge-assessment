<?php

namespace App\Services;

use App\Data\Pixelforge\CostEstimateData;
use App\Data\Pixelforge\PricingData;
use App\Data\Pixelforge\ProviderUsageData;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Support\Money;

final class PricingCalculator
{
    /**
     * @param  list<string>  $referenceImages
     */
    public function estimate(
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        array $referenceImages,
        PricingData $pricing,
    ): CostEstimateData {
        $charCount = mb_strlen($prompt, 'UTF-8');
        $charsPerToken = max(1, $pricing->charactersPerTextToken);
        $estimatedTextTokens = (int) ceil($charCount / $charsPerToken);

        $textTokensMin = (int) floor($estimatedTextTokens * (1.0 - $pricing->textTokenVariance));
        $textTokensMax = (int) ceil($estimatedTextTokens * (1.0 + $pricing->textTokenVariance));

        $imageTokens = count($referenceImages) * $pricing->perReferenceImageTokens;
        $outputTokens = $pricing->outputTokensFor($quality, $size);
        $reasoningRange = $pricing->reasoningRangeFor($quality);

        $rates = $pricing->rates;

        $providerMin = $this->tokenCost($textTokensMin, $rates['input_text_tokens'])
            + $this->tokenCost($imageTokens, $rates['input_image_tokens'])
            + $this->tokenCost($outputTokens, $rates['output_tokens'])
            + $this->tokenCost($reasoningRange['min'], $rates['reasoning_tokens']);

        $providerMax = $this->tokenCost($textTokensMax, $rates['input_text_tokens'])
            + $this->tokenCost($imageTokens, $rates['input_image_tokens'])
            + $this->tokenCost($outputTokens, $rates['output_tokens'])
            + $this->tokenCost($reasoningRange['max'], $rates['reasoning_tokens']);

        return new CostEstimateData(
            inputTextTokensMin: $textTokensMin,
            inputTextTokensMax: $textTokensMax,
            inputImageTokens: $imageTokens,
            outputTokens: $outputTokens,
            reasoningTokensMin: $reasoningRange['min'],
            reasoningTokensMax: $reasoningRange['max'],
            providerMinNanodollars: $providerMin,
            providerMaxNanodollars: $providerMax,
            userMinNanodollars: Money::applyMarkup($providerMin),
            userMaxNanodollars: Money::applyMarkup($providerMax),
            ratesSnapshot: $rates,
        );
    }

    /**
     * @param  array{
     *     input_text_tokens: string,
     *     input_image_tokens: string,
     *     output_tokens: string,
     *     reasoning_tokens: string
     * }  $rates
     */
    public function calculateActualProviderCost(ProviderUsageData $usage, array $rates): int
    {
        return $this->tokenCost($usage->inputTextTokens, $rates['input_text_tokens'])
            + $this->tokenCost($usage->inputImageTokens, $rates['input_image_tokens'])
            + $this->tokenCost($usage->outputTokens, $rates['output_tokens'])
            + $this->tokenCost($usage->reasoningTokens, $rates['reasoning_tokens']);
    }

    public function calculateActualUserCost(int $providerCostNanodollars): int
    {
        return Money::applyMarkup($providerCostNanodollars);
    }

    private function tokenCost(int $tokens, string $ratePerMillionUsd): int
    {
        if ($tokens <= 0) {
            return 0;
        }

        $ratePerMillionNanodollars = Money::fromUsdString($ratePerMillionUsd);

        return intdiv($tokens * $ratePerMillionNanodollars, 1_000_000);
    }
}
