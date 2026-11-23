<?php

namespace App\Http\Controllers\Credits;

use App\Concerns\UsesPipeline;
use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Filters\Sort;
use App\Filters\Transactions\DirectionFilter;
use App\Filters\Transactions\TypeFilter;
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
    use UsesPipeline;

    public function index(Request $request, TransactionPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        $transactions = $this->pipeline(
            $user->wallet->transactions()->with('transactionable')->getQuery(),
        )
            ->paginate(15)
            ->withQueryString()
            ->through($presenter->ledgerRow(...));

        return Inertia::render('Credits/Index', [
            'transactions' => $transactions,
            'filters' => [
                'type' => $this->typeFilter()?->value,
                'direction' => $this->directionFilter()?->value,
                'sort' => $this->sortFilter()->current(),
            ],
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

    /**
     * @return array<int, object>
     */
    protected function filters(): array
    {
        return [
            new TypeFilter($this->typeFilter()),
            new DirectionFilter($this->directionFilter()),
            $this->sortFilter(),
        ];
    }

    private function typeFilter(): ?TransactionType
    {
        $type = request()->query('type');

        return is_string($type) ? TransactionType::tryFrom($type) : null;
    }

    private function directionFilter(): ?TransactionDirection
    {
        $direction = request()->query('direction');

        return is_string($direction) ? TransactionDirection::tryFrom($direction) : null;
    }

    private function sortFilter(): Sort
    {
        $sort = request()->query('sort');

        return new Sort(
            sort: is_string($sort) ? $sort : null,
            allowedColumns: ['created_at', 'amount', 'balance_after'],
            defaultColumn: 'created_at',
        );
    }
}
