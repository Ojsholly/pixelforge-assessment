<?php

use App\Data\Pixelforge\PricingData;
use App\Data\Pixelforge\ProviderUsageData;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\PricingCalculator;
use App\Support\Money;

function samplePricingData(): PricingData
{
    return PricingData::fromArray([
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 272, '1536x1024' => 408, '1024x1536' => 408],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4160, '1536x1024' => 6240, '1024x1536' => 6240],
            ],
            'reasoning' => [
                'low' => ['min' => 50, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 500, 'max' => 6000],
            ],
        ],
    ]);
}

it('calculates exact nanodollar min and max estimates with 25% markup', function (): void {
    $calculator = new PricingCalculator;
    $pricing = samplePricingData();

    // 400 chars => 100 est text tokens => 85..115
    // 2 ref images => 1530 tokens ($0.015300000)
    // high 1536x1024 => 6240 output tokens ($0.249600000)
    // high reasoning => 500..6000 tokens ($0.006250000..$0.075000000)
    $estimate = $calculator->estimate(
        prompt: str_repeat('a', 400),
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        referenceImages: ['https://example.com/1.png', 'https://example.com/2.png'],
        pricing: $pricing,
    );

    // Min provider: 425_000 + 15_300_000 + 249_600_000 + 6_250_000 = 271_575_000
    // Max provider: 575_000 + 15_300_000 + 249_600_000 + 75_000_000 = 340_475_000
    expect($estimate->inputTextTokensMin)->toBe(85)
        ->and($estimate->inputTextTokensMax)->toBe(115)
        ->and($estimate->inputImageTokens)->toBe(1530)
        ->and($estimate->outputTokens)->toBe(6240)
        ->and($estimate->reasoningTokensMin)->toBe(500)
        ->and($estimate->reasoningTokensMax)->toBe(6000)
        ->and($estimate->providerMinNanodollars)->toBe(271_575_000)
        ->and($estimate->providerMaxNanodollars)->toBe(340_475_000)
        ->and($estimate->userMinNanodollars)->toBe(Money::applyMarkup(271_575_000))
        ->and($estimate->userMaxNanodollars)->toBe(Money::applyMarkup(340_475_000))
        ->and($estimate->userMaxNanodollars)->toBe(425_593_750);
});

it('counts unicode multi-byte characters rather than bytes', function (): void {
    $calculator = new PricingCalculator;
    $pricing = samplePricingData();

    // 4 emoji characters (16 UTF-8 bytes) => ceil(4 / 4) = 1 text token => 0..2 range
    $estimate = $calculator->estimate(
        prompt: '✨✨✨✨',
        size: ImageSize::Square,
        quality: ImageQuality::Low,
        referenceImages: [],
        pricing: $pricing,
    );

    expect($estimate->inputTextTokensMin)->toBe(0)
        ->and($estimate->inputTextTokensMax)->toBe(2)
        ->and($estimate->inputImageTokens)->toBe(0);
});

it('calculates exact actual provider cost and 25% markup user cost from usage', function (): void {
    $calculator = new PricingCalculator;
    $pricing = samplePricingData();

    $usage = ProviderUsageData::fromArray([
        'input_text_tokens' => 25,
        'input_image_tokens' => 765,
        'output_tokens' => 1100,
        'reasoning_tokens' => 800,
        'total_tokens' => 2690,
    ]);

    // 25*5000 + 765*10000 + 1100*40000 + 800*12500
    // = 125_000 + 7_650_000 + 44_000_000 + 10_000_000 = 61_775_000
    $providerCost = $calculator->calculateActualProviderCost($usage, $pricing->rates);
    $userCost = $calculator->calculateActualUserCost($providerCost);

    expect($providerCost)->toBe(61_775_000)
        ->and($userCost)->toBe(77_218_750);
});
