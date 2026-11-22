# PR Review Context

## Project Overview

Pixelforge Image Studio (Laravel 13, PHP 8.4, Vue 3, Inertia v3, Wayfinder, Tailwind CSS v4, PostgreSQL 17).
Manages asynchronous AI image generations, exact integer-nanodollar billing (+25% markup), signed idempotent webhooks, and wallet/ledger accounting.

## Epic 01 — Foundation (Current PR)

- **Money Representation (`App\Support\Money`):** All monetary values are stored as signed `BIGINT` **nanodollars** ($10^{-9}$ USD), matching Pixelforge's 9-decimal USD strings (`"0.046125000"`) with zero floating-point arithmetic.
- **Wallets & Polymorphic Ledger:**
  - 1:1 `wallets` table (`user_id` unique, `balance` in nanodollars, `CHECK (balance >= 0)` on PostgreSQL).
  - `User::created` auto-provisions a zero-balance wallet; `create_wallets_table` migration backfills existing users.
  - `transactions` uses `nullableMorphs('transactionable')`. `transactions_wallet_morph_type_unique` (`wallet_id, transactionable_type, transactionable_id, type`) intentionally treats `NULL` morphs as distinct so users can have multiple `purchase` rows while `hold`/`capture`/`release` per `Generation` are unique.
- **State Machine (`spatie/laravel-model-states`):** `Generation::$status` is cast to `GenerationState` (`Queued -> Processing -> Succeeded | Failed`). Terminal states (`Succeeded`, `Failed`) have zero outgoing transitions. `GenerationStatus` enum is retained for validation/filtering/UI.
- **Provider Abstraction:** `App\Contracts\ImageGenerationProvider` + typed `readonly` DTOs under `app/Data/Pixelforge/`, selected via `IMAGE_PROVIDER` (default `pixelforge`).
- **Prompt Sanitization (`App\Support\PromptSanitizer`):** Strips ASCII control chars and enforces valid UTF-8 / 4,000-char limit while intentionally preserving Pixelforge test keywords (`[fail]`, `[slow]`, `[duplicate]`).
- **Quality Gates:** Pint, Rector (PHP 8.4 + Laravel 13), Larastan Level 7, `vp check`, `vue-tsc --noEmit`, and Pest 5 running against both SQLite and PostgreSQL 17.
