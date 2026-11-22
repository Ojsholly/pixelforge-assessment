<?php

use App\Models\User;
use App\Services\Pixelforge\PixelforgeProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('requires authentication to estimate generation costs', function (): void {
    $this->postJson(route('generations.estimate'), [
        'prompt' => 'A sunset',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertUnauthorized();
});

it('returns an exact nanodollar cost estimate for authenticated users', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    config(['services.pixelforge.api_key' => 'test-key']);

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => str_repeat('a', 40),
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'tokens' => ['input_text_min', 'input_text_max', 'input_image', 'output', 'reasoning_min', 'reasoning_max'],
            'provider_min_nanodollars',
            'provider_max_nanodollars',
            'user_min_nanodollars',
            'user_max_nanodollars',
            'user_min_usd',
            'user_max_usd',
            'user_min_display',
            'user_max_display',
            'rates_snapshot',
        ]);

    expect($response->json('user_max_nanodollars'))->toBeGreaterThan($response->json('provider_max_nanodollars'));
});

it('validates estimate inputs and returns 503 when pricing provider fails', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('generations.estimate'), [
        'size' => 'invalid-size',
        'quality' => 'ultra',
        'reference_images' => [
            'not-a-url',
            'https://example.com/2.png',
            'https://example.com/3.png',
            'https://example.com/4.png',
            'https://example.com/5.png',
        ],
        'prompt' => str_repeat('a', 4001),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['size', 'quality', 'reference_images', 'reference_images.0', 'prompt']);

    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    config(['services.pixelforge.api_key' => '']);

    $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => 'Valid prompt',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertServiceUnavailable()
        ->assertJson(['message' => 'Unable to fetch pricing estimate right now. Please try again.']);
});
