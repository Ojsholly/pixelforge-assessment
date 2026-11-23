---
paths:
    - 'app/**'
---

# App

## Organize code by feature subdirectory

Code is grouped by domain subdirectory inside each layer (Http/Controllers/Generations, Http/Requests/Credits, Filters/Transactions, Data/Pixelforge, Services/Pixelforge, States/Generation, Actions/Generations). Place new classes in the matching feature subdirectory; tests/Feature mirrors the same grouping.

## Money is integer nanodollars; wallet mutations via WalletService

All monetary values are int nanodollars (1 USD = 1_000_000_000) — never floats or cents. Format and parse through App\Support\Money. Every wallet balance change goes through WalletService inside DB::transaction with lockForUpdate() and writes an append-only Transaction row with balance_after; never update wallets.balance directly.

## Static Str:: helpers

Use static Str::* methods (Str::endsWith, Str::lower) — not Str::of() fluent chains or native str_* functions where a Str equivalent exists.

## Immutable dates via CarbonImmutable

Dates are immutable: AppServiceProvider sets Date::use(CarbonImmutable::class), and models/DTOs type-hint CarbonImmutable. Do not introduce mutable Carbon instances.
