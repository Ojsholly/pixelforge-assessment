<?php

use App\Contracts\ImageGenerationProvider;
use App\Exceptions\ProviderException;
use App\Services\Pixelforge\PixelforgeProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('resolves PixelforgeProvider from the container by default', function (): void {
    expect(app(ImageGenerationProvider::class))->toBeInstanceOf(PixelforgeProvider::class);
});

it('fetches and caches pricing from GET /v1/pricing', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
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
                    'low' => ['1024x1024' => 272],
                    'medium' => ['1024x1024' => 1100],
                    'high' => ['1024x1024' => 4160],
                ],
                'reasoning' => [
                    'low' => ['min' => 50, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 500, 'max' => 6000],
                ],
            ],
        ], 200),
    ]);

    $provider = app(ImageGenerationProvider::class);

    $first = $provider->getPricing();
    $second = $provider->getPricing();

    expect($first->perReferenceImageTokens)->toBe(765)
        ->and($first->rates['output_tokens'])->toBe('40.00')
        ->and($second->charactersPerTextToken)->toBe(4);

    Http::assertSentCount(1);
});

it('throws ProviderException when Pixelforge pricing fails', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'error' => [
                'code' => 'overloaded',
                'message' => 'Service temporarily overloaded',
            ],
        ], 503),
    ]);

    $provider = app(ImageGenerationProvider::class);

    expect(fn () => $provider->getPricing())->toThrow(ProviderException::class, 'Service temporarily overloaded');
});
