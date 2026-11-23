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
        // where composer install ran with --no-dev.
        if (class_exists(Factory::class)) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // Env-gated seeders run only when their credentials are configured,
        // so a plain `db:seed` stays safe without them.
        if (filled(config('app.seed_admin.email')) && filled(config('app.seed_admin.password'))) {
            $this->call(AdminUserSeeder::class);
        }

        if (filled(config('app.seed_user.email')) && filled(config('app.seed_user.password'))) {
            $this->call(DemoUserSeeder::class);
        }
    }
}
