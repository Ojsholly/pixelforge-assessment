<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Grant admin access to a user by email.')]
#[Signature('user:make-admin {email : Email of the user to promote to admin}')]
class MakeUserAdminCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        /** @var string $email */
        $email = $this->argument('email');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("[{$email}] is already an admin.");

            return self::SUCCESS;
        }

        // Promotion revokes demo status — an account cannot be both publicly
        // exposable via demo login and an admin.
        $user->is_admin = true;
        $user->is_demo = false;
        $user->save();

        $this->info("[{$email}] is now an admin.");

        return self::SUCCESS;
    }
}
