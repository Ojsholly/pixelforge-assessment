<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'wallet' => function () use ($request): ?array {
                $user = $request->user();

                if (! $user instanceof User) {
                    return null;
                }

                $wallet = app(WalletService::class)->ensureWallet($user);

                return [
                    'balance' => $wallet->balance,
                    'balance_display' => $wallet->formatted_balance,
                ];
            },
            'flash' => [
                'status' => $request->session()->get('status'),
            ],
            'demoLogin' => (bool) config('app.demo_login.enabled') && filled(config('app.demo_login.email')),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
