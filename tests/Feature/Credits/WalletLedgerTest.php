<?php

use App\Enums\TransactionDirection;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

function ledgerRow(
    int $walletId,
    TransactionType $type,
    TransactionDirection $direction,
    int $amount,
    int $balanceAfter,
    ?Carbon $createdAt = null,
): Transaction {
    return Transaction::forceCreate([
        'wallet_id' => $walletId,
        'direction' => $direction,
        'type' => $type,
        'amount' => $amount,
        'balance_after' => $balanceAfter,
        'description' => 'Test row',
        'created_at' => $createdAt ?? now(),
    ]);
}

it('filters the ledger by transaction type', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    $purchase = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 100, 100);
    ledgerRow($wallet->id, TransactionType::Hold, TransactionDirection::Debit, 40, 60);
    ledgerRow($wallet->id, TransactionType::Release, TransactionDirection::Credit, 40, 100);

    $this->actingAs($user)
        ->get(route('credits.index', ['type' => 'purchase']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Credits/Index')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.id', $purchase->id)
            ->where('filters.type', 'purchase')
        );
});

it('filters the ledger by direction', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 100, 100);
    $debit = ledgerRow($wallet->id, TransactionType::Hold, TransactionDirection::Debit, 40, 60);

    $this->actingAs($user)
        ->get(route('credits.index', ['direction' => 'debit']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.id', $debit->id)
            ->where('filters.direction', 'debit')
        );
});

it('combines type and direction filters', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 100, 100);
    ledgerRow($wallet->id, TransactionType::Release, TransactionDirection::Credit, 40, 140);
    ledgerRow($wallet->id, TransactionType::Hold, TransactionDirection::Debit, 40, 60);

    // purchase + credit keeps the purchase; hold + credit finds nothing.
    $this->actingAs($user)
        ->get(route('credits.index', ['type' => 'purchase', 'direction' => 'credit']))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 1));

    $this->actingAs($user)
        ->get(route('credits.index', ['type' => 'hold', 'direction' => 'credit']))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 0));
});

it('sorts the ledger by amount and balance_after, newest first by default', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    $small = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 10, 10, Carbon::parse('2026-01-01'));
    $large = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 300, 310, Carbon::parse('2026-01-03'));
    $medium = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 100, 410, Carbon::parse('2026-01-02'));

    $this->actingAs($user)
        ->get(route('credits.index'))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.id', $large->id)
            ->where('filters.sort', '-created_at')
        );

    $this->actingAs($user)
        ->get(route('credits.index', ['sort' => 'amount']))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.id', $small->id)
            ->where('transactions.data.2.id', $large->id)
            ->where('filters.sort', 'amount')
        );

    $this->actingAs($user)
        ->get(route('credits.index', ['sort' => '-balance_after']))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.id', $medium->id)
            ->where('filters.sort', '-balance_after')
        );
});

it('sorts amounts by signed value so credits outrank debits', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    $creditFive = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 5, 5);
    $debitForty = ledgerRow($wallet->id, TransactionType::Hold, TransactionDirection::Debit, 40, -35);
    $creditTen = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 10, -25);

    // Descending signed order: +10 > +5 > -40. Magnitude would put -40 first.
    $this->actingAs($user)
        ->get(route('credits.index', ['sort' => '-amount']))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.id', $creditTen->id)
            ->where('transactions.data.1.id', $creditFive->id)
            ->where('transactions.data.2.id', $debitForty->id)
        );
});

it('orders equal signed amounts by id so pagination is stable', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    $first = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 100, 100);
    $smallest = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 50, 150);
    $second = ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 100, 250);

    $this->actingAs($user)
        ->get(route('credits.index', ['sort' => 'amount']))
        ->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.id', $smallest->id)
            ->where('transactions.data.1.id', $first->id)
            ->where('transactions.data.2.id', $second->id)
        );
});

it('ignores invalid filter and sort values', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, 100, 100);

    $this->actingAs($user)
        ->get(route('credits.index', [
            'type' => 'bogus',
            'direction' => ['nested'],
            'sort' => '; DROP TABLE transactions;--',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('filters.type', null)
            ->where('filters.direction', null)
            ->where('filters.sort', '-created_at')
        );
});

it('paginates the ledger while preserving filter and sort params', function (): void {
    $user = User::factory()->create();
    $wallet = $user->wallet()->firstOrFail();

    for ($i = 0; $i < 20; $i++) {
        ledgerRow($wallet->id, TransactionType::Purchase, TransactionDirection::Credit, $i + 1, $i + 1);
    }
    ledgerRow($wallet->id, TransactionType::Hold, TransactionDirection::Debit, 5, 95);

    $response = $this->actingAs($user)
        ->get(route('credits.index', ['type' => 'purchase', 'sort' => 'amount', 'page' => 2]))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 5)
        ->where('transactions.current_page', 2)
    );

    $links = $response->viewData('page')['props']['transactions']['links'];

    collect($links)->pluck('url')->filter()->each(
        fn (string $url) => expect($url)->toContain('type=purchase')->toContain('sort=amount'),
    );
});
