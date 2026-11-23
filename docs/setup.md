# Setup

## Prerequisites

- PHP 8.4+ with Composer
- Node `^22.18.0 || ^24.11.0 || >=26.0.0` (Vite+ toolchain requirement)
- A database: Postgres (production parity) or SQLite (zero-config local)
- A Pixelforge API key — register at
  <https://pixelforge.promptedit.dev/register> (docs:
  <https://pixelforge.promptedit.dev/docs>)

## Install

```bash
composer setup   # composer install, .env, key:generate, migrate --seed, npm ci, build
```

If you'd rather do it by hand:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm ci && npm run build
```

## Environment variables

Application-specific settings on top of the Laravel starter kit:

| Variable                                   | Required | Purpose                                                                                    |
| ------------------------------------------ | -------- | ------------------------------------------------------------------------------------------ |
| `IMAGE_PROVIDER`                           | yes      | Provider driver — `pixelforge`                                                             |
| `PIXELFORGE_BASE_URL`                      | yes      | API base **including `/v1`**, e.g. `https://pixelforge.promptedit.dev/v1`                  |
| `PIXELFORGE_API_KEY`                       | yes      | Bearer key for all provider calls                                                          |
| `PIXELFORGE_WEBHOOK_SECRET`                | yes      | HMAC secret for `POST /webhooks/pixelforge` signature verification                         |
| `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` | no       | Opt-in: `AdminUserSeeder` creates this admin on `db:seed`. Never promote an existing user. |
| `QUEUE_CONNECTION`                         | yes      | `database` — webhook processing + reconciliation are queued                                |
| `CACHE_STORE`                              | yes      | any real store (`database` locally) — pricing is cached 5 min                              |

## Run

```bash
php artisan serve            # app
npm run dev                  # Vite dev server
php artisan queue:work --queue=default,reconciliation  # REQUIRED — webhooks drain first, reconcile second
php artisan schedule:work    # REQUIRED for stale-generation reconciliation
```

or all-in-one during development:

```bash
composer dev                 # serve + queue:listen + vite + log tail via concurrently
```

> The queue worker is **not optional**: webhook events are persisted and then
> processed by `ProcessWebhookEvent` (`ShouldBeUnique`). Without a worker,
> events are accepted but never settle. The scheduler polls for generations
> whose webhooks never arrived (`generations:reconcile-stale`, every 5 min).

## Webhooks in local development

Point Pixelforge's webhook delivery at your local app via a tunnel:

```bash
npx localtunnel --port 8000 --subdomain <your-subdomain>   # artisan serve default port; use 80 under Laravel Herd
# set PIXELFORGE webhook URL to https://<subdomain>.loca.lt/webhooks/pixelforge
```

`VerifyPixelforgeWebhookSignature` middleware enforces a 256 KB body cap and
the `Pixelforge-Signature` HMAC before any decoding or DB writes.

## Admin access

```bash
php artisan user:make-admin you@example.com
```

or seed one: set `SEED_ADMIN_EMAIL` + `SEED_ADMIN_PASSWORD`, then
`php artisan db:seed --class=AdminUserSeeder` (`AdminUserSeeder` only creates
new users and refuses to promote existing ones — `DatabaseSeeder` never calls
it). The report lives at `/admin/report` (403 for non-admins).

## Tests & checks

```bash
composer test          # Pint + Rector dry-run + PHPStan + full Pest suite
composer ci:check      # same gates as CI
npm run build          # production build (vp build)
```
