# PR Review Context

## Project Overview

Pixelforge Image Studio (Laravel 13, PHP 8.4, Vue 3, Inertia v3, Wayfinder, Tailwind CSS v4, PostgreSQL 17).
Manages asynchronous AI image generations, exact integer-nanodollar billing (+25% markup), signed idempotent webhooks, and wallet/ledger accounting.

## Epic 04 — Webhooks, Signature Verification & Idempotency (Current PR)

- **Signature Verification (`PixelforgeProvider::verifyWebhookSignature`):** Parses `Pixelforge-Signature` (`t=<ts>,v1=<hex>`), enforces a 300s replay window, and uses constant-time `hash_equals` on `"{$timestamp}.{$rawPayload}"` via PHP 8.4 `array_any`.
- **CSRF & Auth Exemption (`bootstrap/app.php`, `routes/web.php`):** `POST /webhooks/pixelforge` is outside `auth` middleware and excluded from CSRF verification.
- **Crash-Safe Idempotency (`PixelforgeWebhookController`):** Uses `insertOrIgnore` on `webhook_events.provider_event_id` (`UNIQUE`), locks the `WebhookEvent` row inside `DB::transaction`, skips if `processed_at !== null`, matches `Generation` by `provider_id` or `metadata.local_generation_id`, calls `ApplyGenerationStateAction`, and sets `processed_at` in the same transaction. Unknown events, `ping`, and orphan events are preserved and acknowledged with `204`.
- **Stale Reconciliation (`ReconcileStaleGenerationsCommand` + `routes/console.php`):** Scheduled every 5 minutes (`withoutOverlapping()`) to poll `GET /v1/generations/{id}` for stale `queued`/`processing` rows. Provider errors are classified deliberately: `404` fails immediately (`reconciliation_not_found`), still-pending past `--max-age` (60m) times out (`reconciliation_timeout`), and transient errors (5xx/connection/malformed) are reported and retried next run until `--hard-max-age` (24h) bounds the hold (`reconciliation_unreachable`). Transient errors never permanently fail a generation within the retry window.
- **Defensive Payload Parsing (`ProviderGenerationData::fromArray`):** Required semantic fields (`id`, `status`, `image_url`) are type-validated and reject with `InvalidArgumentException` (wrapped as `ProviderException` 502 `malformed_response`, acknowledged on webhooks / transient on polls). Optional informational fields — all four timestamps and `error.code`/`error.message` — tolerate malformed values as `null`, so a bad `completed_at` can neither poison a webhook mid-transaction nor fail an already-accepted `queued` submission.
- **Webhook Data Retention:** `webhook_events.payload` contains prompt text and image URLs, so processed events are mass-pruned daily after 30 days (`model:prune` in `routes/console.php`, unprocessed events are kept for crash recovery), and `User::deleting` purges a deleted account's webhook events before `generations.user_id` cascades.

## Epic 03 — Generation Lifecycle & Create Studio UI (Merged)

- **Centralized Error Handling (`bootstrap/app.php` + `ProviderException`):** `ProviderException` is rendered centrally in `bootstrap/app.php` (JSON `{ message, error_code }` for `expectsJson()`, or `back()->withErrors(['provider' => ...])` for Inertia) with structured `context()`. Controllers have zero `try/catch` blocks.
- **Two-Transaction Boundary (`CreateGenerationAction`):** TX1 locks `wallets`, pre-creates `Generation` (`status: Queued`, `idempotency_key`), and reserves `estimated_max_user_cost` if funded *before* calling `POST /v1/generations` (so racing webhooks match via `metadata.local_generation_id`). Outbound HTTP retries `503`/timeouts with the same `Idempotency-Key`. TX2 reconciles `202` or transitions to `Failed` + releases 100% of hold on error.
- **Idempotent State Machine (`ApplyGenerationStateAction`):** Uses `lockForUpdate()` + `GenerationState::canTransitionTo()` out-of-order guard; computes exact nanodollar costs and settles surplus/shortfall on `Succeeded` or refunds 100% on `Failed`.
- **SSRF Guard (`SafeExternalUrl`):** Validates `http`/`https` schemes and rejects `localhost`, `.local`, `.internal`, and private/reserved IP ranges on `reference_images.*`.
- **Create Studio UI (`resources/js/pages/Generations/Create.vue`):** Inertia v3 + Wayfinder + Tailwind v4 with debounced live cost estimate, Unicode char counter, 0–4 reference URLs, and inline provider error display.

## Epic 02 — Pricing Pipeline (Merged)

- **`ImageGenerationProvider` Binding (`AppServiceProvider`):** Resolves `PixelforgeProvider` from `config('services.image_provider.driver')` (default `pixelforge`). Remaining provider methods (`createGeneration`, `getGeneration`, `getUsage`, `verifyWebhookSignature`) intentionally throw `LogicException` until Epics 03, 04, and 07.
- **`PixelforgeProvider::getPricing()`:** Fetches `GET /v1/pricing` with retry on transient `ConnectionException`/5xx, caches for 5 minutes (`pixelforge:pricing`), and hydrates `PricingData`.
- **`PricingCalculator` & `CostEstimateData`:** Uses `mb_strlen(..., 'UTF-8')` for character-based token estimation, computes `intdiv($tokens * $ratePerMillionNanodollars, 1_000_000)` in integer nanodollars (zero floats), and applies exact half-up 25% markup (`Money::applyMarkup`).
- **Live Estimate Endpoint:** `POST /generate/estimate` (`EstimateGenerationController`, `EstimateGenerationRequest`, throttled `60,1`).

## Epic 01 — Foundation (Merged)

- **Money Representation (`App\Support\Money`):** All monetary values are stored as signed `BIGINT` **nanodollars** ($10^{-9}$ USD), matching Pixelforge's 9-decimal USD strings (`"0.046125000"`) with zero floating-point arithmetic.
- **Wallets & Polymorphic Ledger:**
  - 1:1 `wallets` table (`user_id` unique, `balance` in nanodollars, `CHECK (balance >= 0)` on PostgreSQL).
  - `User::created` auto-provisions a zero-balance wallet; `create_wallets_table` migration backfills existing users.
  - `transactions` uses `nullableMorphs('transactionable')`. `transactions_wallet_morph_type_unique` (`wallet_id, transactionable_type, transactionable_id, type`) intentionally treats `NULL` morphs as distinct so users can have multiple `purchase` rows while `hold`/`capture`/`release` per `Generation` are unique.
- **State Machine (`spatie/laravel-model-states`):** `Generation::$status` is cast to `GenerationState` (`Queued -> Processing -> Succeeded | Failed`). Terminal states (`Succeeded`, `Failed`) have zero outgoing transitions. `GenerationStatus` enum is retained for validation/filtering/UI.
- **Provider Abstraction:** `App\Contracts\ImageGenerationProvider` + typed `readonly` DTOs under `app/Data/Pixelforge/`, selected via `IMAGE_PROVIDER` (default `pixelforge`).
- **Prompt Sanitization (`App\Support\PromptSanitizer`):** Strips ASCII control chars and enforces valid UTF-8 / 4,000-char limit while intentionally preserving Pixelforge test keywords (`[fail]`, `[slow]`, `[duplicate]`).
- **Quality Gates:** Pint, Rector (PHP 8.4 + Laravel 13), Larastan Level 7, `vp check`, `vue-tsc --noEmit`, and Pest 5 running against both SQLite and PostgreSQL 17.
