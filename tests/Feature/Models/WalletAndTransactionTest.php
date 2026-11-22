<?php

use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Models\Generation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('provisions a zero-balance wallet when a user is created', function (): void {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'wallet-test@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect();

    $user = User::where('email', 'wallet-test@example.com')->firstOrFail();

    expect($user->wallet)->not->toBeNull()
        ->and($user->wallet->balance)->toBe(0)
        ->and($user->wallet->formatted_balance)->toBe('$0.00');
});

it('links transactions polymorphically to generations and prevents duplicate hold entries', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();
    $wallet->balance = 5 * Money::NANODOLLARS_PER_USD;
    $wallet->save();

    $generation = Generation::factory()->for($user)->create();

    $transaction = Transaction::forceCreate([
        'wallet_id' => $wallet->id,
        'transactionable_type' => $generation->getMorphClass(),
        'transactionable_id' => $generation->id,
        'direction' => TransactionDirection::Debit,
        'type' => TransactionType::Hold,
        'amount' => 100_000_000,
        'balance_after' => 4_900_000_000,
        'description' => 'Hold for generation',
    ]);

    expect($transaction->transactionable->is($generation))->toBeTrue()
        ->and($generation->transactions)->toHaveCount(1);

    expect(fn () => Transaction::forceCreate([
        'wallet_id' => $wallet->id,
        'transactionable_type' => $generation->getMorphClass(),
        'transactionable_id' => $generation->id,
        'direction' => TransactionDirection::Debit,
        'type' => TransactionType::Hold,
        'amount' => 100_000_000,
        'balance_after' => 4_800_000_000,
        'description' => 'Duplicate hold',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('enforces owner-only access in GenerationPolicy', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $generation = Generation::factory()->for($owner)->create();

    expect($owner->can('view', $generation))->toBeTrue()
        ->and($owner->can('refresh', $generation))->toBeTrue()
        ->and($other->can('view', $generation))->toBeFalse()
        ->and($other->can('refresh', $generation))->toBeFalse();
});

it('creates wallets and transactions via default factories without colliding with user provisioning', function (): void {
    $wallet = Wallet::factory()->withBalance(250_000_000)->create();
    $transaction = Transaction::factory()->create();

    expect($wallet->balance)->toBe(250_000_000)
        ->and($wallet->user->wallet->is($wallet))->toBeTrue()
        ->and($transaction->wallet)->not->toBeNull();
});

it('backfills zero-balance wallets for existing users when the wallet migration runs', function (): void {
    $userId = DB::table('users')->insertGetId([
        'name' => 'Legacy User',
        'email' => 'legacy@example.com',
        'password' => bcrypt('Password123!'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Schema::dropIfExists('transactions');
    Schema::dropIfExists('wallets');

    (require database_path('migrations/2026_11_22_000002_create_wallets_table.php'))->up();
    (require database_path('migrations/2026_11_22_000004_create_transactions_table.php'))->up();

    $legacyUser = User::findOrFail($userId);

    expect($legacyUser->wallet)->not->toBeNull()
        ->and($legacyUser->wallet->balance)->toBe(0);
});
