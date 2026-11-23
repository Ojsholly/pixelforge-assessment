# PixelForge Assessment

An async image-generation studio built on the fictional Pixelforge API. Users
submit prompts, preview a live cost estimate (provider rates + 25% markup),
fund a wallet, and receive finished images via signed, idempotent webhooks —
with exact nanodollar billing and an admin usage/profit report reconciled
against the provider's own usage endpoint.

**Live demo:** https://pixelforge-assessment-production-bkqmqs.laravel.cloud

## Quick start

```bash
composer setup          # install deps, create .env, migrate + seed, build
npm run dev             # Vite dev server (separate terminal)
php artisan queue:work  # queue worker (webhook + reconcile jobs)
php artisan schedule:work  # scheduler (stale-generation reconciliation)
```

Then set the Pixelforge credentials in `.env` — see
[docs/setup.md](docs/setup.md) for the full guide.

## Documentation

| Document                                         | Contents                                                                                                   |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| [docs/setup.md](docs/setup.md)                   | Prerequisites, `.env` reference, run instructions, queue worker + scheduler, admin setup, tests            |
| [docs/architecture.md](docs/architecture.md)     | Money model (nanodollars), generation lifecycle, webhook pipeline, wallet/ledger semantics, security model |
| [docs/pixelforge-api.md](docs/pixelforge-api.md) | Provider contract used by the app, signature scheme, idempotency, test prompts                             |

## Stack

Laravel 13 · PHP 8.4+ · Inertia v3 · Vue 3 · Tailwind v4 · Wayfinder ·
Postgres (or SQLite locally) · Pest · PHPStan/Larastan · Rector · Pint
