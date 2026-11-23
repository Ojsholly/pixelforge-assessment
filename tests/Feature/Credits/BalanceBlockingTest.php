<?php

use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use App\States\Generation\Failed;
use App\States\Generation\Succeeded;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('blocks submission when the wallet balance is below the estimated max cost', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response(['id' => 'gen_unfunded', 'status' => 'queued'], 202),
    ]);

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse at dusk',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors('balance');

    // The rolled-back hold leaves no generation row, no ledger rows, and the
    // provider submission endpoint is never called.
    expect(Generation::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);

    Http::assertNotSent(
        fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/generations'),
    );
});

it('blocks a second submission once the first hold depletes the balance', function (): void {
    seedPricingCacheAndKey();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_deplete',
            'status' => 'queued',
            'rates' => validPricingPayload()['rates'],
        ], 202),
    ]);

    $user = User::factory()->create();

    // Fund exactly the quoted maximum through the real estimate endpoint.
    $estimate = $this->actingAs($user)->postJson(route('generations.estimate'), [
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertOk()->json('user_max_nanodollars');

    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = $estimate;
    $wallet->save();

    $payload = [
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
    ];

    $this->actingAs($user)->post(route('generations.store'), $payload)
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasNoErrors();

    expect($wallet->refresh()->balance)->toBe(0);

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), $payload)
        ->assertSessionHasErrors('balance');

    expect(Generation::count())->toBe(1)
        ->and(Transaction::count())->toBe(1)
        ->and(Transaction::sole()->type)->toBe(TransactionType::Hold);
});

it('settles a succeeded generation with an exact charge, surplus release, and consistent ledger', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations/gen_settle' => Http::response([
            'id' => 'gen_settle',
            'status' => 'succeeded',
            'rates' => validPricingPayload()['rates'],
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_settle.png',
            'image_url_expires_at' => '2026-11-23T12:00:15Z',
            'usage' => [
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
                'total_tokens' => 1610,
            ],
            'completed_at' => '2026-11-22T12:00:15Z',
        ], 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_settle',
            'status' => 'queued',
            'rates' => validPricingPayload()['rates'],
        ], 202),
    ]);

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertSessionHasNoErrors();

    $generation = Generation::sole();
    $held = $generation->estimated_max_user_cost;

    expect($held)->toBeGreaterThan(0)
        ->and($generation->reserved_amount)->toBe($held)
        ->and($wallet->refresh()->balance)->toBe((5 * Money::NANODOLLARS_PER_USD) - $held);

    $hold = Transaction::sole();
    expect($hold->type)->toBe(TransactionType::Hold)
        ->and($hold->direction)->toBe(TransactionDirection::Debit)
        ->and($hold->amount)->toBe($held)
        ->and($hold->balance_after)->toBe((5 * Money::NANODOLLARS_PER_USD) - $held);

    $this->actingAs($user)
        ->from(route('generations.show', $generation))
        ->post(route('generations.refresh', $generation))
        ->assertRedirect(route('generations.show', $generation));

    $generation->refresh();
    $expectedUserCost = 62_875_000;

    expect($generation->status)->toBeInstanceOf(Succeeded::class)
        ->and($generation->actual_user_cost)->toBe($expectedUserCost)
        ->and($generation->reserved_amount)->toBe(0)
        ->and($wallet->refresh()->balance)->toBe((5 * Money::NANODOLLARS_PER_USD) - $expectedUserCost);

    $release = Transaction::where('type', TransactionType::Release)->sole();

    // Ledger invariant: every row's balance_after equals the running balance
    // immediately after that mutation.
    expect($release->direction)->toBe(TransactionDirection::Credit)
        ->and($release->amount)->toBe($held - $expectedUserCost)
        ->and($release->balance_after)->toBe($hold->balance_after + $release->amount)
        ->and($release->balance_after)->toBe($wallet->balance);
});

it('refunds the entire hold when a generation fails', function (): void {
    seedPricingCacheAndKey();

    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    Http::fake([
        'https://pixelforge.promptedit.dev/v1/pricing' => Http::response(validPricingPayload(), 200),
        'https://pixelforge.promptedit.dev/v1/generations/gen_refund' => Http::response([
            'id' => 'gen_refund',
            'status' => 'failed',
            'rates' => validPricingPayload()['rates'],
            'error' => ['code' => 'content_policy', 'message' => 'Prompt rejected.'],
            'completed_at' => '2026-11-22T12:00:15Z',
        ], 200),
        'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_refund',
            'status' => 'queued',
            'rates' => validPricingPayload()['rates'],
        ], 202),
    ]);

    $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => '[fail] A lighthouse at dusk',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertSessionHasNoErrors();

    $generation = Generation::sole();
    $held = $generation->reserved_amount;

    $this->actingAs($user)
        ->from(route('generations.show', $generation))
        ->post(route('generations.refresh', $generation))
        ->assertRedirect(route('generations.show', $generation));

    $generation->refresh();

    expect($generation->status)->toBeInstanceOf(Failed::class)
        ->and($generation->actual_user_cost)->toBe(0)
        ->and($generation->reserved_amount)->toBe(0)
        ->and($wallet->refresh()->balance)->toBe(5 * Money::NANODOLLARS_PER_USD);

    $release = Transaction::where('type', TransactionType::Release)->sole();

    expect($release->amount)->toBe($held)
        ->and($release->balance_after)->toBe(5 * Money::NANODOLLARS_PER_USD);
});
