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

Verified demo accounts exist on the live deployment — their credentials are
shared in the private submission notes rather than committed to this repo:

| Account    | Email                     | Notes                              |
| ---------- | ------------------------- | ---------------------------------- |
| Demo user  | `demo@pixelforge.studio`  | Pre-verified, $25.00 wallet credit |
| Demo admin | `admin@pixelforge.studio` | Access to `/admin/report`          |

Provision equivalent accounts locally two ways — passwords are never
hardcoded:

```bash
# Let the seeders generate strong passwords (printed to the console once):
SEED_USER_EMAIL=demo@example.test SEED_USER_CREDITS_USD=25.00 \
SEED_ADMIN_EMAIL=admin@example.test php artisan db:seed

# Or supply your own via the environment:
SEED_USER_EMAIL=demo@example.test SEED_USER_PASSWORD='pick-your-own' \
SEED_USER_CREDITS_USD=25.00 SEED_ADMIN_EMAIL=admin@example.test \
SEED_ADMIN_PASSWORD='pick-your-own' php artisan db:seed
```

`db:seed` only creates accounts whose `SEED_*_EMAIL` is configured (persisted
`.env` values work the same). An existing demo email is skipped, while a
configured admin email that already belongs to a **non-admin** stops the seed
with the `user:make-admin` guidance — promoting silently could hand a fresh
password to a live session. Seeding can never reset a live password or
double-fund a wallet. Email verification is skipped for seeded accounts,
which matters on deployments whose mail driver only logs.

### One-click demo login

When `DEMO_LOGIN_ENABLED=true`, the landing and login pages show a **Try the
demo** button that authenticates the visitor as `DEMO_LOGIN_EMAIL` (which
defaults to `SEED_USER_EMAIL`) and lands them on the dashboard — no password
required. The endpoint is a throttled, CSRF-protected `POST /demo-login` that
404s when disabled, when the configured email does not resolve to a user, or
when it resolves to an admin — so it can only ever expose the seeded demo
account, never a real user. Leave it disabled anywhere real accounts exist.

## Documentation

| Document                                         | Contents                                                                                                   |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| [docs/setup.md](docs/setup.md)                   | Prerequisites, `.env` reference, run instructions, queue worker + scheduler, admin setup, tests            |
| [docs/architecture.md](docs/architecture.md)     | Money model (nanodollars), generation lifecycle, webhook pipeline, wallet/ledger semantics, security model |
| [docs/pixelforge-api.md](docs/pixelforge-api.md) | Provider contract used by the app, signature scheme, idempotency, test prompts                             |

## Stack

Laravel 13 · PHP 8.4+ · Inertia v3 · Vue 3 · Tailwind v4 · Wayfinder ·
Postgres (or SQLite locally) · Pest · PHPStan/Larastan · Rector · Pint
