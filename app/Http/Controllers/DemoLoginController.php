<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click demo login: POST /demo-login authenticates the visitor as the
 * designated demo account. The route is always registered but fails closed —
 * a 404 unless DEMO_LOGIN_ENABLED is set AND the configured email resolves
 * to a non-admin user. Binding to a configured account (instead of "any user
 * with credits") means the button can never hand over a real user's session.
 */
class DemoLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $email = config('app.demo_login.email');
        $user = is_string($email) ? User::query()->where('email', $email)->first() : null;

        abort_unless(
            config('app.demo_login.enabled') && $user instanceof User && ! $user->is_admin,
            404
        );

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
