<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Opt-in admin account: run `php artisan db:seed --class=AdminUserSeeder` with
 * SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD set in the environment. Credentials
 * are never hardcoded — the seeder refuses to run without them.
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
        $password = config('app.seed_admin.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException(
                'Set SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD to seed an admin user.',
            );
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'is_admin' => true,
            ],
        );
    }
}
