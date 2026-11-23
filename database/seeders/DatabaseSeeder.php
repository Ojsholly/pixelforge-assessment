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
        // so a plain `db:seed` stays safe without them. AdminUserSeeder throws
        // on a registered email — check first so its RuntimeException can't
        // abort a repeated seed before DemoUserSeeder runs.
        $adminEmail = config('app.seed_admin.email');

        if (
            filled($adminEmail) && filled(config('app.seed_admin.password'))
            && ! User::query()->where('email', $adminEmail)->exists()
        ) {
            $this->call(AdminUserSeeder::class);
        }

        if (filled(config('app.seed_user.email')) && filled(config('app.seed_user.password'))) {
            $this->call(DemoUserSeeder::class);
        }
    }
}
