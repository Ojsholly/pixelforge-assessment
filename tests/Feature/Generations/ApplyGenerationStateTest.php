<?php

use App\Actions\Generations\ApplyGenerationStateAction;
use App\Data\Pixelforge\ProviderGenerationData;
use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\User;
use App\States\Generation\Failed;
use App\States\Generation\Processing;
use App\States\Generation\Succeeded;
use App\Support\Money;

it('transitions queued -> processing -> succeeded, records exact costs, and releases surplus hold', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    $holdAmount = 100_000_000;
    $wallet->balance -= $holdAmount;
    $wallet->save();

    $generation = Generation::factory()->for($user)->create([
        'reserved_amount' => $holdAmount,
        'estimated_max_user_cost' => $holdAmount,
    ]);

    $action = app(ApplyGenerationStateAction::class);

    $processing = ProviderGenerationData::fromArray([
        'id' => 'gen_test_1',
        'status' => 'processing',
        'rates' => $generation->rates_snapshot,
        'started_at' => '2026-11-22T12:00:05Z',
    ]);

    $generation = $action->execute($generation, $processing);
    expect($generation->status)->toBeInstanceOf(Processing::class);

    $succeeded = ProviderGenerationData::fromArray([
        'id' => 'gen_test_1',
        'status' => 'succeeded',
        'rates' => $generation->rates_snapshot,
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_test_1.png',
        'image_url_expires_at' => '2026-11-23T12:00:15Z',
        'usage' => [
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 500,
            'total_tokens' => 1610,
        ],
        'completed_at' => '2026-11-22T12:00:15Z',
    ]);

    // Provider cost: 10*5000 + 1100*40000 + 500*12500 = 50_000 + 44_000_000 + 6_250_000 = 50_300_000
    // User cost (+25%): 62_875_000
    // Surplus released: 100_000_000 - 62_875_000 = 37_125_000
    $generation = $action->execute($generation, $succeeded);

    expect($generation->status)->toBeInstanceOf(Succeeded::class)
        ->and($generation->actual_provider_cost)->toBe(50_300_000)
        ->and($generation->actual_user_cost)->toBe(62_875_000)
        ->and($generation->reserved_amount)->toBe(0)
        ->and($wallet->refresh()->balance)->toBe((5 * Money::NANODOLLARS_PER_USD) - 62_875_000)
        ->and($generation->transactions()->where('type', TransactionType::Release)->count())->toBe(1);

    // Out-of-order processing event after succeeded must not regress or double-settle
    $generation = $action->execute($generation, $processing);
    expect($generation->status)->toBeInstanceOf(Succeeded::class)
        ->and($wallet->refresh()->balance)->toBe((5 * Money::NANODOLLARS_PER_USD) - 62_875_000);
});

it('transitions queued -> failed, records zero cost, and refunds 100% of hold', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $holdAmount = 80_000_000;
    $wallet->balance = (5 * Money::NANODOLLARS_PER_USD) - $holdAmount;
    $wallet->save();

    $generation = Generation::factory()->for($user)->create([
        'reserved_amount' => $holdAmount,
        'estimated_max_user_cost' => $holdAmount,
    ]);

    $action = app(ApplyGenerationStateAction::class);

    $failed = ProviderGenerationData::fromArray([
        'id' => 'gen_test_fail',
        'status' => 'failed',
        'rates' => $generation->rates_snapshot,
        'error' => [
            'code' => 'generation_failed',
            'message' => 'Simulated failure',
        ],
        'completed_at' => '2026-11-22T12:00:10Z',
    ]);

    $generation = $action->execute($generation, $failed);

    expect($generation->status)->toBeInstanceOf(Failed::class)
        ->and($generation->actual_provider_cost)->toBe(0)
        ->and($generation->actual_user_cost)->toBe(0)
        ->and($generation->reserved_amount)->toBe(0)
        ->and($wallet->refresh()->balance)->toBe(5 * Money::NANODOLLARS_PER_USD);
});
