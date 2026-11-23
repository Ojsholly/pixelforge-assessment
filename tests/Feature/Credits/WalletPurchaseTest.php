<?php

use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;

it('requires authentication for the credits page and purchases', function (): void {
    $this->get(route('credits.index'))->assertRedirect(route('login'));
    $this->post(route('credits.purchase'), ['amount_usd' => '10.00'])
        ->assertRedirect(route('login'));
});

it('renders the credits page with the wallet ledger', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('credits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Credits/Index')
            ->has('transactions.data', 0)
            ->where('wallet.balance', 0)
            ->where('wallet.balance_display', '$0.00')
        );
});

it('credits the wallet and writes a purchase ledger row', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('credits.index'))
        ->post(route('credits.purchase'), ['amount_usd' => '10.00'])
        ->assertRedirect(route('credits.index'));

    $wallet = $user->wallet()->firstOrFail();

    expect($wallet->refresh()->balance)->toBe(10 * Money::NANODOLLARS_PER_USD);

    $transaction = $wallet->transactions()->sole();

    expect($transaction->direction)->toBe(TransactionDirection::Credit)
        ->and($transaction->type)->toBe(TransactionType::Purchase)
        ->and($transaction->amount)->toBe(10 * Money::NANODOLLARS_PER_USD)
        ->and($transaction->balance_after)->toBe(10 * Money::NANODOLLARS_PER_USD)
        ->and($transaction->transactionable_type)->toBeNull()
        ->and($transaction->transactionable_id)->toBeNull();
});

it('stacks purchases on top of the existing balance', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['amount_usd' => '25.00'])
        ->assertRedirect(route('credits.index'));

    expect($wallet->refresh()->balance)->toBe(30 * Money::NANODOLLARS_PER_USD)
        ->and($wallet->transactions()->sole()->balance_after)
        ->toBe(30 * Money::NANODOLLARS_PER_USD);
});

it('rejects non-positive, malformed, and oversized purchase amounts', function (string $amount): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('credits.index'))
        ->post(route('credits.purchase'), ['amount_usd' => $amount])
        ->assertSessionHasErrors('amount_usd');

    expect($user->wallet()->firstOrFail()->refresh()->balance)->toBe(0)
        ->and(Transaction::count())->toBe(0);
})->with([
    'zero' => '0',
    'negative' => '-5',
    'zero decimal' => '0.00',
    'non-numeric' => 'abc',
    'scientific notation' => '1e3',
    'comma separator' => '5,00',
    'above maximum' => '1000.01',
    'too many decimals' => '5.1234567891',
]);

it('accepts the maximum purchase and sub-cent precision', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['amount_usd' => '1000.00'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->post(route('credits.purchase'), ['amount_usd' => '0.005'])
        ->assertSessionHasNoErrors();

    expect($user->wallet()->firstOrFail()->refresh()->balance)
        ->toBe(1_000_005_000_000);
});
