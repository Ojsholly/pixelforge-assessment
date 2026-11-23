<?php

namespace App\Support;

use App\Models\User;

/**
 * Resolves the account a visitor may one-click into when demo login is
 * enabled. The eligibility rule is shared by DemoLoginController (the
 * authoritative gate) and the Inertia `demoLogin` flag (which hides the
 * button when the account is not yet seeded): enabled, configured email
 * resolves, and the account is a flagged demo user that is not an admin.
 *
 * Requiring `is_demo` — set only by DemoUserSeeder — means the button can
 * never hand over an ordinary account that merely shares the configured
 * email, even if that account predates the seed.
 */
final class DemoLogin
{
    public static function eligibleUser(): ?User
    {
        if (! config('app.demo_login.enabled')) {
            return null;
        }

        $email = config('app.demo_login.email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        return $user instanceof User && $user->is_demo && ! $user->is_admin ? $user : null;
    }
}
