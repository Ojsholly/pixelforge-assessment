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

## Error handling: boundary try/catch only, typed exceptions, central render

Controllers and request-facing code never try/catch - exceptions bubble to the handler. try/catch belongs only at integration boundaries and must translate the failure into a typed domain exception or compensate: PixelforgeProvider wraps ConnectionException/RequestException/InvalidArgumentException as ProviderException, WalletService retries UniqueConstraintViolationException in ensureWallet, and actions like CreateGenerationAction catch ProviderException only to transition the generation to Failed and release the hold. ProviderException is rendered centrally in bootstrap/app.php (Inertia back-with-errors or JSON for expectsJson). Never log-and-swallow or silently degrade on a caught exception.

## Never cache objects — cache config blocks unserialization

config/cache.php sets serializable_classes=false, so every cached object unserializes to __PHP_Incomplete_Class on read. Cache only scalars and arrays (wire payloads); hydrate DTOs/models on every read.

## Queued work lives in app/Jobs; commands only orchestrate

Jobs implement ShouldQueue plus ShouldBeUnique when duplicate dispatches are possible (uniqueId() keys the dedup; the unique lock holds through queueing and processing, released on finish/fail). Commands and controllers select work and dispatch jobs or Bus::batch — multi-step per-item logic lives in an Action the job calls, never inline in the command.
