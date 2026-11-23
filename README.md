# PixelForge Assessment

An async image-generation studio built on the fictional Pixelforge API. Users
submit prompts, preview a live cost estimate (provider rates + 25% markup),
fund a wallet, and receive finished images via signed, idempotent webhooks —
with exact nanodollar billing and an admin usage/profit report reconciled
against the provider's own usage endpoint.

**Live demo:** https://pixelforge-assessment-production-bkqmqs.laravel.cloud

## Quick start

```bash
composer setup          # install deps, create .env, migrate, build
php artisan db:seed     # demo accounts (only when SEED_* envs are set — see below)
npm run dev             # Vite dev server (separate terminal)
php artisan queue:work --queue=default,reconciliation  # webhooks first, reconcile second
php artisan schedule:work  # scheduler (stale-generation reconciliation)
```

Then set the Pixelforge credentials in `.env` — see
[docs/setup.md](docs/setup.md) for the full guide.

## Demo accounts

Seed accounts exist on the live demo. Create the same accounts locally by
seeding with their credentials — the demo credentials below are published on
purpose for this take-home; rotate them for any real deployment and treat
the demo accounts as shared/public (anyone with the URL can log in, so they
may be reset periodically):

| Account    | Email                     | Password            | Notes                              |
| ---------- | ------------------------- | ------------------- | ---------------------------------- |
| Demo user  | `demo@pixelforge.studio`  | `Demo-Studio-2026!` | Pre-verified, $25.00 wallet credit |
| Demo admin | `admin@pixelforge.studio` | `Demo-Admin-2026!`  | Access to `/admin/report`          |

```bash
SEED_USER_EMAIL=demo@pixelforge.studio SEED_USER_PASSWORD='Demo-Studio-2026!' \
SEED_USER_CREDITS_USD=25.00 SEED_ADMIN_EMAIL=admin@pixelforge.studio \
SEED_ADMIN_PASSWORD='Demo-Admin-2026!' php artisan db:seed
```

The same values work persisted in `.env` (`SEED_*` keys) — `db:seed` only
creates accounts whose env credentials are configured, and skips emails that
already exist. Email verification is skipped for seeded accounts, which
matters on deployments whose mail driver only logs.

## Documentation

| Document                                         | Contents                                                                                                   |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| [docs/setup.md](docs/setup.md)                   | Prerequisites, `.env` reference, run instructions, queue worker + scheduler, admin setup, tests            |
| [docs/architecture.md](docs/architecture.md)     | Money model (nanodollars), generation lifecycle, webhook pipeline, wallet/ledger semantics, security model |
| [docs/pixelforge-api.md](docs/pixelforge-api.md) | Provider contract used by the app, signature scheme, idempotency, test prompts                             |

## Stack

Laravel 13 · PHP 8.4+ · Inertia v3 · Vue 3 · Tailwind v4 · Wayfinder ·
Postgres (or SQLite locally) · Pest · PHPStan/Larastan · Rector · Pint
