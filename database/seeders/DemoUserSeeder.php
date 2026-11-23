<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Opt-in demo account: run `php artisan db:seed --class=DemoUserSeeder` with
 * SEED_USER_EMAIL and SEED_USER_PASSWORD set in the environment. Credentials
 * are never hardcoded — the seeder refuses to run without them.
 * SEED_USER_CREDITS_USD optionally funds the wallet (default `0.00`).
 *
 * Like AdminUserSeeder it only ever creates a fresh account; an existing
 * email is skipped so re-seeding can never reset a live password or
 * double-fund a wallet.
 */
class DemoUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = config('app.seed_user.email');
        $password = config('app.seed_user.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException(
                'Set SEED_USER_EMAIL and SEED_USER_PASSWORD to seed a demo user.',
            );
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("[{$email}] already exists — skipping demo user seeding.");

            return;
        }

        // User creation and the welcome credit commit atomically: a bad
        // SEED_USER_CREDITS_USD must not leave an account the existing-email
        // guard would then skip forever.
        DB::transaction(function () use ($email, $password): void {
            $user = new User;
            $user->forceFill([
                'name' => (string) config('app.seed_user.name', 'Demo User'),
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ])->save();

            $credits = Money::fromUsdString((string) config('app.seed_user.credits_usd', '0.00'));

            if ($credits > 0) {
                $walletService = app(WalletService::class);
                $walletService->ensureWallet($user);
                $walletService->purchase($user, $credits, 'Welcome credit');
            }
        });
    }
}
