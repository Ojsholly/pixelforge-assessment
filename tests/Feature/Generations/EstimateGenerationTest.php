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
                    'medium' => ['1024x1024' => 1100],
                ],
                'reasoning' => [
                    'medium' => ['min' => 200, 'max' => 2000],
                ],
            ],
        ], 200),
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
});
