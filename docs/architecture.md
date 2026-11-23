# Architecture

## Stack & boundaries

- **Laravel 13 + Inertia v3 + Vue 3 + Tailwind v4 + Wayfinder** — typed
  route/action functions (`@/routes`, `@/actions`); no hardcoded URLs.
- **`App\Contracts\ImageGenerationProvider`** — the port every provider call
  and webhook-verification goes through. `PixelforgeProvider` is the only
  implementation; a second provider needs no consumer changes.
- **Actions** own single use cases (`CreateGenerationAction`,
  `ApplyGenerationStateAction`, `ProcessWebhookEventAction`,
  `ReconcileGenerationAction`, `UsageReportAction`); controllers and jobs are
  thin wrappers.

## Money model — integer nanodollars

`1 USD = 1,000,000,000` nanodollars, stored as signed `BIGINT` everywhere —
identical in SQLite and Postgres (which lacks `UNSIGNED`), and matching
Pixelforge's 9-decimal `cost_usd` wire format 1:1. No float ever touches
money.

- `Money::applyMarkup()` applies +25% exactly once:
  `intdiv(($n * 125) + 50, 100)` (half-up; with published rates the remainder
  is always zero).
- Wallet holds reserve `estimated_max_user_cost` at submit. On `succeeded`,
  surplus is released (`release`); if actual ever exceeds the hold, the
  shortfall is captured up to the wallet balance (`capture`) — balance can
  never go negative (also a Postgres `CHECK` constraint). On `failed`, the
  entire hold is released and costs record `0`.
- The ledger (`transactions`) is append-only: `purchase`, `hold`, `release`,
  `capture` rows make every balance change explainable.

## Generation lifecycle

`Queued → Processing → Succeeded | Failed`
(`spatie/laravel-model-states` state machine).

1. **TX1** — `CreateGenerationAction` locks the `User` row (serialized
   against account deletion), creates the `Generation` with a UUID
   `idempotency_key`, snapshots rates + estimate, and places the hold —
   all _before_ the provider call.
2. **Provider submit** — `POST /v1/generations` with `Idempotency-Key` and
   `metadata.local_generation_id` (fallback matcher for orphaned events).
3. **TX2** — the `202` response reconciles via `ApplyGenerationStateAction`,
   which is a safe no-op if a webhook already arrived.

`ProviderGenerationData` refuses a `succeeded` payload missing `usage` or
`image_url`, so a malformed success can never settle at `$0`.

## Webhook pipeline

`POST /webhooks/pixelforge` → `VerifyPixelforgeWebhookSignature` middleware →
`PixelforgeWebhookController` → `ProcessWebhookEvent` job →
`ProcessWebhookEventAction`.

- **Gate (middleware):** `Content-Length` pre-check, `strlen` byte cap
  (256 KB → 413), then `t={ts},v1={hmac}` verified with `hash_equals` over the
  raw body within ±300 s (→ 400). Signature runs before JSON decode or any DB
  write.
- **Persist (controller):** decode + envelope validation →
  `insertOrIgnore` on `provider_event_id` (UNIQUE) → dispatch
  `ProcessWebhookEvent` (`ShouldBeUnique` on the event id) → `204`. Always
  inside the 10 s provider timeout.
- **Settle (action):** `lockForUpdate` the event row → `processed_at` guard
  → lock the `Generation` → `canTransitionTo` state-machine check
  (out-of-order `processing` after a terminal event is a no-op) → wallet
  settle/release. Lock order is always event → generation → wallet.
- **Backstop:** `generations:reconcile-stale` every 5 min
  (`withoutOverlapping`) polls `GET /v1/generations/{id}` for pending rows
  and times out hopeless ones to release holds.

## Admin report & reconciliation

`/admin/report` (`EnsureUserIsAdmin`) groups local **succeeded** generations
by UTC `date(completed_at)` — matching Pixelforge's billing clock — and joins
net wallet debits so `user_spend` (billed) is distinct from `collected`
(money actually taken) and `uncollected`. Each day and total is compared with
`GET /v1/usage?start_date&end_date`; the `delta` column surfaces
provider-vs-app mismatches (e.g. generations created outside the app).

## Security model

- Owner-scoped generation access (403 cross-user, incl. admin); admin gate on
  `/admin/*`.
- `User::$fillable = [name,email,password]` — `is_admin` can never arrive
  via mass assignment.
- `SafeExternalUrl` on reference images: https/http only, no
  `localhost`/`.local`/`.internal`, no private/reserved IP ranges (incl.
  IPv4-mapped IPv6 + DNS resolution).
- `PromptSanitizer` strips ASCII control characters, preserves Unicode, and
  keeps the provider's test keywords (`[fail]`, `[slow]`, `[duplicate]`).
- Per-route `throttle:` limits on store/estimate/refresh/purchase; CSRF
  exempt **only** for the signed webhook route.
- Account deletion scrubs PII (`prompt='[deleted]'`, null `reference_images`,
  `image_url`) while keeping the financial rows the report reconciles on;
  in-flight webhooks still settle through the anonymized wallet.

## Notable invariants

- `serializable_classes => false` in `config/cache.php` — objects must never
  be cached; `getPricing` caches the validated wire array.
- Unique-job dedup is dispatch-time only — Laravel **batches bypass
  `ShouldBeUnique`**, so the reconcile command dispatches individual jobs.
