<?php

use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\User;
use App\States\Generation\Failed;
use App\States\Generation\Queued;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('requires authentication to view or submit generations', function (): void {
    $this->get(route('generations.create'))->assertRedirect(route('login'));
    $this->post(route('generations.store'), [])->assertRedirect(route('login'));
});

it('renders the generate studio page for verified users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('generations.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Generations/Create')
            ->has('sizes', 3)
            ->has('qualities', 3)
        );
});

it('creates a generation with idempotency key, metadata, and wallet hold on 202', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_accepted_123',
            'status' => 'queued',
            'rates' => validPricingPayload()['rates'],
            'metadata' => ['local_generation_id' => '1'],
            'created_at' => '2026-11-22T12:00:00Z',
        ], 202),
    ]);

    $response = $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A neon-lit ramen shop in Kyoto',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ]);

    $response->assertRedirect(route('generations.create'))
        ->assertSessionHas('status');

    $generation = Generation::firstOrFail();

    expect($generation->provider_id)->toBe('gen_accepted_123')
        ->and($generation->status)->toBeInstanceOf(Queued::class)
        ->and($generation->reserved_amount)->toBe($generation->estimated_max_user_cost)
        ->and($wallet->refresh()->balance)->toBe((5 * Money::NANODOLLARS_PER_USD) - $generation->estimated_max_user_cost)
        ->and($generation->transactions()->where('type', TransactionType::Hold)->count())->toBe(1);

    Http::assertSent(function (Request $request) use ($generation, $user): bool {
        if (! str_ends_with($request->url(), '/generations')) {
            return false;
        }

        return $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
            && ($request['metadata']['local_generation_id'] ?? null) === (string) $generation->id
            && ($request['metadata']['user_id'] ?? null) === (string) $user->id;
    });
});

it('keeps an accepted generation queued when optional response timestamps are malformed', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_accepted_bad_ts',
            'status' => 'queued',
            'rates' => validPricingPayload()['rates'],
            'metadata' => ['local_generation_id' => '1'],
            'created_at' => '2026-11-22T12:00:00Z',
            'completed_at' => 'not-a-timestamp',
        ], 202),
    ]);

    $response = $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A neon-lit ramen shop in Kyoto',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ]);

    $response->assertRedirect(route('generations.create'));

    $generation = Generation::firstOrFail();

    expect($generation->provider_id)->toBe('gen_accepted_bad_ts')
        ->and($generation->status)->toBeInstanceOf(Queued::class)
        ->and($generation->reserved_amount)->toBe($generation->estimated_max_user_cost);
});

it('transitions to failed and releases hold on 400 content_policy_violation without retrying', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'code' => 'content_policy_violation',
                'message' => 'Prompt violates safety policy.',
            ],
        ], 400),
    ]);

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => 'Policy violating prompt',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors('provider');

    $generation = Generation::firstOrFail();

    expect($generation->status)->toBeInstanceOf(Failed::class)
        ->and($generation->error_code)->toBe('content_policy_violation')
        ->and($generation->actual_provider_cost)->toBe(0)
        ->and($generation->actual_user_cost)->toBe(0)
        ->and($generation->reserved_amount)->toBe(0)
        ->and($wallet->refresh()->balance)->toBe(5 * Money::NANODOLLARS_PER_USD);

    Http::assertSentCount(2); // 1 pricing + 1 generation (no retry on 400)
});

it('retries 503 overloaded with the same idempotency key and releases hold if retries exhaust', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    $seenKeys = [];

    Http::fake(function (Request $request) use (&$seenKeys) {
        if (str_ends_with($request->url(), '/pricing')) {
            return Http::response(validPricingPayload(), 200);
        }

        $seenKeys[] = $request->header('Idempotency-Key')[0] ?? null;

        return Http::response([
            'error' => [
                'code' => 'overloaded',
                'message' => 'Provider overloaded',
            ],
        ], 503);
    });

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => 'Overloaded test',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors('provider');

    $generation = Generation::firstOrFail();

    expect($seenKeys)->toHaveCount(3) // 1 initial + 2 retries
        ->and(array_unique($seenKeys))->toBe([$generation->idempotency_key])
        ->and($generation->status)->toBeInstanceOf(Failed::class)
        ->and($wallet->refresh()->balance)->toBe(5 * Money::NANODOLLARS_PER_USD);
});

it('does not retry 429 concurrency limit and releases hold immediately', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'error' => [
                'code' => 'concurrency_limit_exceeded',
                'message' => 'Too many concurrent requests.',
            ],
        ], 429, ['Retry-After' => '5']),
    ]);

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => 'Concurrency test',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors('provider');

    $generation = Generation::firstOrFail();
    expect($generation->status)->toBeInstanceOf(Failed::class)
        ->and($generation->error_code)->toBe('concurrency_limit_exceeded');

    Http::assertSentCount(2);
});

it('blocks SSRF internal urls including IPv6 brackets, IPv4-mapped IPv6, and control-only prompts', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => "\x00\x01\x02",
        'size' => 'invalid',
        'quality' => 'invalid',
        'reference_images' => [
            'http://127.0.0.1/secret.png',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/secret.png',
            'http://[::ffff:127.0.0.1]/secret.png',
        ],
    ])->assertSessionHasErrors([
        'prompt',
        'size',
        'quality',
        'reference_images.0',
        'reference_images.1',
        'reference_images.2',
        'reference_images.3',
    ]);
});

it('accepts internationalized and public IPv6 reference URLs', function (): void {
    seedPricingCacheAndKey();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_idn_1',
            'status' => 'queued',
            'rates' => validPricingPayload()['rates'],
        ], 202),
    ]);

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'Test IDN and public IPv6 urls',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [
            'https://bücher.de/image.png',
            'https://[2606:4700::1111]/reference.png',
        ],
    ])->assertRedirect(route('generations.create'))
        ->assertSessionHasNoErrors();
});

it('does not allow estimate requests to exhaust the generation submission throttle', function (): void {
    seedPricingCacheAndKey();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_throttle_1',
            'status' => 'queued',
            'rates' => validPricingPayload()['rates'],
        ], 202),
    ]);

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    for ($i = 0; $i < 31; $i++) {
        $this->actingAs($user)->postJson(route('generations.estimate'), [
            'prompt' => 'A sunset',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertOk();
    }

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A sunset',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertRedirect(route('generations.create'))
        ->assertSessionHas('status');
});
