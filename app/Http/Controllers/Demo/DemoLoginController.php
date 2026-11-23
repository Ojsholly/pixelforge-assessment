<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DemoLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click demo login: POST /demo-login authenticates the visitor as the
 * designated demo account. The route is always registered but fails closed —
 * a 404 unless DemoLogin resolves an eligible account (enabled, seeded with
 * `is_demo`, not an admin). Binding to a configured, flagged account —
 * instead of "any user with credits" — means the button can never hand over
 * a real user's session.
 */
class DemoLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = DemoLogin::eligibleUser();

        abort_unless($user instanceof User, 404);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
