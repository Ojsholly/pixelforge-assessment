<?php

use App\Contracts\ImageGenerationProvider;
use App\Exceptions\ProviderException;
use App\Services\Pixelforge\PixelforgeProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function validPricingPayload(): array
{
    return [
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
    ];
}

it('resolves PixelforgeProvider from the container by default', function (): void {
    expect(app(ImageGenerationProvider::class))->toBeInstanceOf(PixelforgeProvider::class);
});

it('fetches and caches public pricing from GET /v1/pricing without requiring an API key', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    config(['services.pixelforge.api_key' => '']);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
    ]);

    $provider = app(ImageGenerationProvider::class);

    $first = $provider->getPricing();
    $second = $provider->getPricing();

    expect($first->perReferenceImageTokens)->toBe(765)
        ->and($first->rates['output_tokens'])->toBe('40.00')
        ->and($second->charactersPerTextToken)->toBe(4);

    Http::assertSentCount(1);
});

it('throws ProviderException without caching when Pixelforge pricing fails', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    config(['services.pixelforge.api_key' => 'test-key']);

    Http::fakeSequence('https://pixelforge.promptedit.dev/v1/pricing')
        ->push(['error' => ['code' => 'overloaded', 'message' => 'Service temporarily overloaded']], 503)
        ->push(['error' => ['code' => 'overloaded', 'message' => 'Service temporarily overloaded']], 503)
        ->push(['error' => ['code' => 'overloaded', 'message' => 'Service temporarily overloaded']], 503)
        ->push(validPricingPayload(), 200);

    $provider = app(ImageGenerationProvider::class);

    expect(fn () => $provider->getPricing())->toThrow(ProviderException::class, 'Service temporarily overloaded');

    // Subsequent call should retry HTTP because the failure was not cached
    expect($provider->getPricing()->perReferenceImageTokens)->toBe(765);
});

it('wraps ConnectionException in ProviderException', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    config(['services.pixelforge.api_key' => 'test-key']);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => fn () => throw new ConnectionException('Timed out'),
    ]);

    $provider = app(ImageGenerationProvider::class);
    expect(fn () => $provider->getPricing())->toThrow(ProviderException::class, 'Could not connect to Pixelforge pricing endpoint.');
});

it('wraps malformed pricing payloads and scalar JSON responses in ProviderException', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);

    Http::fakeSequence('https://pixelforge.promptedit.dev/v1/pricing')
        ->push(['rates' => []], 200)
        ->push('"ok"', 200)
        ->push(array_replace_recursive(validPricingPayload(), [
            'tokens' => ['reasoning' => ['low' => ['min' => 500, 'max' => 50]]],
        ]), 200);

    $provider = app(ImageGenerationProvider::class);

    expect(fn () => $provider->getPricing())->toThrow(ProviderException::class, 'Malformed pricing payload');

    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    expect(fn () => $provider->getPricing())->toThrow(ProviderException::class, 'Malformed pricing payload: expected JSON object.');

    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    expect(fn () => $provider->getPricing())->toThrow(ProviderException::class, 'invalid reasoning range');
});
