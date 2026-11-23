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
php artisan queue:work --queue=default,reconciliation  # webhooks first, reconcile second
php artisan schedule:work  # scheduler (stale-generation reconciliation)
```

Then set the Pixelforge credentials in `.env` — see
[docs/setup.md](docs/setup.md) for the full guide.

## Demo accounts

Seed accounts exist on the live demo and are created locally by `db:seed`
(the `SEED_*` env vars ship as defaults in `.env.example` — committed on
purpose for this take-home; rotate them for any real deployment):

| Account    | Email                     | Password            | Notes                              |
| ---------- | ------------------------- | ------------------- | ---------------------------------- |
| Demo user  | `demo@pixelforge.studio`  | `Demo-Studio-2026!` | Pre-verified, $25.00 wallet credit |
| Demo admin | `admin@pixelforge.studio` | `Demo-Admin-2026!`  | Access to `/admin/report`          |

```bash
php artisan migrate:fresh --seed   # local: seeds demo user + admin when SEED_* is set
php artisan db:seed                # adds them to an existing database (skipped if present)
```

Email verification is skipped for seeded accounts, which matters on
deployments whose mail driver only logs.

## Documentation

| Document                                         | Contents                                                                                                   |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| [docs/setup.md](docs/setup.md)                   | Prerequisites, `.env` reference, run instructions, queue worker + scheduler, admin setup, tests            |
| [docs/architecture.md](docs/architecture.md)     | Money model (nanodollars), generation lifecycle, webhook pipeline, wallet/ledger semantics, security model |
| [docs/pixelforge-api.md](docs/pixelforge-api.md) | Provider contract used by the app, signature scheme, idempotency, test prompts                             |

## Stack

Laravel 13 · PHP 8.4+ · Inertia v3 · Vue 3 · Tailwind v4 · Wayfinder ·
Postgres (or SQLite locally) · Pest · PHPStan/Larastan · Rector · Pint
