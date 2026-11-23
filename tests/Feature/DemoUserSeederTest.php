<?php

use App\Enums\TransactionType;
use App\Models\User;
use App\Services\WalletService;
use App\Support\Money;
use Database\Seeders\DemoUserSeeder;

it('creates a verified demo user with the configured wallet credit', function (): void {
    config([
        'app.seed_user.email' => 'demo@example.com',
        'app.seed_user.password' => 'demo-secret',
        'app.seed_user.name' => 'Demo User',
        'app.seed_user.credits_usd' => '25.00',
    ]);

    $this->artisan('db:seed', ['--class' => DemoUserSeeder::class])
        ->assertSuccessful();

    $user = User::where('email', 'demo@example.com')->sole();

    expect($user->is_admin)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->wallet->balance)->toBe(25 * Money::NANODOLLARS_PER_USD)
        ->and(
            $user->wallet->transactions()
                ->where('type', TransactionType::Purchase->value)
                ->count()
        )->toBe(1);
});

it('skips an existing email without resetting the password or double-funding', function (): void {
    $existing = User::factory()->create(['email' => 'demo@example.com']);
    app(WalletService::class)->purchase($existing, 100, 'existing credit');
    $originalPassword = $existing->password;

    config([
        'app.seed_user.email' => 'demo@example.com',
        'app.seed_user.password' => 'new-secret',
        'app.seed_user.credits_usd' => '25.00',
    ]);

    $this->artisan('db:seed', ['--class' => DemoUserSeeder::class])
        ->assertSuccessful();

    $existing->refresh();

    expect($existing->password)->toBe($originalPassword)
        ->and($existing->wallet->balance)->toBe(100)
        ->and(
            $existing->wallet->transactions()
                ->where('type', TransactionType::Purchase->value)
                ->count()
        )->toBe(1);
});

it('refuses to run without configured credentials', function (): void {
    // Clear explicitly so the result can't depend on the local .env.
    config(['app.seed_user.email' => null, 'app.seed_user.password' => null]);

    expect(fn () => $this->artisan('db:seed', ['--class' => DemoUserSeeder::class]))
        ->toThrow(RuntimeException::class, 'SEED_USER_EMAIL');
});

it('treats a blank configured credit as no credit', function (): void {
    config([
        'app.seed_user.email' => 'demo@example.com',
        'app.seed_user.password' => 'demo-secret',
        'app.seed_user.credits_usd' => '',
    ]);

    $this->artisan('db:seed', ['--class' => DemoUserSeeder::class])
        ->assertSuccessful();

    $user = User::where('email', 'demo@example.com')->sole();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->wallet)->toBeNull();
});

it('leaves no account behind when the configured credit is malformed', function (): void {
    config([
        'app.seed_user.email' => 'demo@example.com',
        'app.seed_user.password' => 'demo-secret',
        'app.seed_user.credits_usd' => 'not-a-number',
    ]);

    expect(fn () => $this->artisan('db:seed', ['--class' => DemoUserSeeder::class]))
        ->toThrow(InvalidArgumentException::class);

    expect(User::where('email', 'demo@example.com')->exists())->toBeFalse();
});

it('seeds repeatedly through DatabaseSeeder without duplicating accounts or funds', function (): void {
    config([
        'app.seed_admin.email' => 'admin@example.com',
        'app.seed_admin.password' => 'admin-secret',
        'app.seed_user.email' => 'demo@example.com',
        'app.seed_user.password' => 'demo-secret',
        'app.seed_user.credits_usd' => '25.00',
    ]);

    $this->artisan('db:seed')->assertSuccessful();
    $this->artisan('db:seed')->assertSuccessful();

    $demo = User::where('email', 'demo@example.com')->sole();

    expect($demo->wallet->balance)->toBe(25 * Money::NANODOLLARS_PER_USD)
        ->and(User::where('email', 'admin@example.com')->sole()->is_admin)->toBeTrue()
        ->and(User::where('email', 'test@example.com')->count())->toBe(1);
});
