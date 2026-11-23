<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opt-in admin account: run `php artisan db:seed --class=AdminUserSeeder` with
 * SEED_ADMIN_EMAIL set in the environment. Credentials are never hardcoded —
 * when SEED_ADMIN_PASSWORD is also set it is used; otherwise a strong password
 * is generated and printed to the console once.
 *
 * It only ever creates a fresh account: promoting an already-registered email
 * would hand the new admin password to whoever owns its existing sessions.
 * Promote existing users with `php artisan user:make-admin {email}` instead.
 */
class AdminUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = config('app.seed_admin.email');

        if (! is_string($email) || $email === '') {
            throw new RuntimeException('Set SEED_ADMIN_EMAIL to seed an admin user.');
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException(
                "[{$email}] is already registered — promote it with: php artisan user:make-admin {$email}",
            );
        }

        $password = config('app.seed_admin.password');
        $generated = ! is_string($password) || $password === '';
        $password = $generated ? Str::password() : $password;

        $user = new User;
        $user->forceFill([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();

        if ($generated) {
            $this->command?->warn("Admin seeded for [{$email}] with generated password: {$password}");
        }
    }
}
