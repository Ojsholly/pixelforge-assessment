<?php

namespace Database\Seeders;

use App\Models\User;
use Faker\Factory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The starter factory user needs Faker, a dev dependency — skip it
        // where composer install ran with --no-dev. Guarded on email so a
        // repeated db:seed never trips the unique constraint.
        if (class_exists(Factory::class) && ! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // Env-gated seeders run only when their credentials are configured,
        // so a plain `db:seed` stays safe without them. When the configured
        // admin email is already an admin, skip (idempotent re-run); when it
        // exists as a non-admin, let AdminUserSeeder throw — its message
        // points at `user:make-admin`, which a silent skip would hide.
        $adminEmail = config('app.seed_admin.email');

        if (
            filled($adminEmail) && filled(config('app.seed_admin.password'))
            && ! User::query()->where('email', $adminEmail)->where('is_admin', true)->exists()
        ) {
            $this->call(AdminUserSeeder::class);
        }

        if (filled(config('app.seed_user.email')) && filled(config('app.seed_user.password'))) {
            $this->call(DemoUserSeeder::class);
        }
    }
}
