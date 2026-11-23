<?php

namespace App\Http\Controllers\Credits;

use App\Http\Controllers\Controller;
use App\Http\Requests\Credits\PurchaseCreditsRequest;
use App\Models\User;
use App\Services\WalletService;
use App\Support\Money;
use App\Support\TransactionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WalletController extends Controller
{
    public function index(Request $request, TransactionPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        $transactions = $user->wallet->transactions()
            ->with('transactionable')
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through($presenter->ledgerRow(...));

        return Inertia::render('Credits/Index', [
            'transactions' => $transactions,
        ]);
    }

    public function purchase(PurchaseCreditsRequest $request, WalletService $wallets): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $amountNanodollars = Money::fromUsdString((string) $request->validated('amount_usd'));

        $wallets->purchase($user, $amountNanodollars, 'Credit purchase');

        return to_route('credits.index')->with('status', 'Added '.Money::formatDisplay($amountNanodollars).' to your wallet.');
    }
}
