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

it('returns an exact nanodollar cost estimate for authenticated users even with an empty API key', function (): void {
    Cache::forget(PixelforgeProvider::PRICING_CACHE_KEY);
    config(['services.pixelforge.api_key' => '']);

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
        ->assertJson([
            'provider_min_nanodollars' => 46_540_000,
            'provider_max_nanodollars' => 69_060_000,
            'user_min_nanodollars' => 58_175_000,
            'user_max_nanodollars' => 86_325_000,
            'user_min_usd' => '0.058175000',
            'user_max_usd' => '0.086325000',
        ]);
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
    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'error' => ['code' => 'overloaded', 'message' => 'Upstream overloaded'],
        ], 503),
    ]);

    $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => 'Valid prompt',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertServiceUnavailable()
        ->assertJson(['message' => 'Unable to fetch pricing estimate right now. Please try again.']);
});
