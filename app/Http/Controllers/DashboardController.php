<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Services\WalletService;
use App\Support\GenerationPresenter;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, GenerationPresenter $presenter, WalletService $wallets): Response
    {
        /** @var User $user */
        $user = $request->user();

        $recentGenerations = Generation::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map($presenter->summary(...));

        return Inertia::render('Dashboard', [
            'walletBalance' => Money::formatDisplay($wallets->ensureWallet($user)->balance),
            'counts' => $presenter->statusCounts($user),
            'recentGenerations' => $recentGenerations,
        ]);
    }
}
