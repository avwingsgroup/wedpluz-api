# Wedpluz API

REST API for **Wedpluz** — events, ticketing, vendors, communities, messaging, wallet and the admin console — serving the web app, the Android and iOS apps, and the admin console from one versioned surface at `/api/v1`.

Built with Laravel 13, PostgreSQL, Redis and Sanctum. It implements the frontend contract in [`docs/backend-handoff/`](docs/backend-handoff/); the implementation's OpenAPI spec is [`docs/api-documentation.yaml`](docs/api-documentation.yaml).

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `mbstring`, `openssl`, `intl`, `gd`, `bcmath`, `curl`, `zip`, `fileinfo`
- Composer 2
- PostgreSQL 15+
- Redis 6+

## Quick start (local)

```bash
composer install
cp .env.example .env
php artisan key:generate

# edit .env: DB_* for your local PostgreSQL, MAIL_MAILER=log, AUTH_DEV_FIXED_OTP=1234 (optional),
#            ADMIN_BOOTSTRAP_EMAIL / ADMIN_BOOTSTRAP_PASSWORD for the first admin
createdb wedpluz
php artisan migrate --seed       # schema + reference data + bootstrap admin (prints the TOTP secret)

php artisan serve                # http://localhost:8000/api/v1/health
php artisan wedpluz:health --ping-queue   # full dependency report + queue round trip
php artisan queue:work redis --queue=critical,notifications,broadcasts,default,low --sleep=1 --timeout=150
php artisan queue:work redis-long --queue=long --timeout=930
php artisan schedule:work        # expiry, reminders, settlements, retention…
php artisan reverb:start         # optional, only with BROADCAST_CONNECTION=reverb
```

Point the frontend at `VITE_API_BASE_URL=http://localhost:8000/api/v1`. Local Vite origins (`localhost:5173`/`5174`) are allowed by CORS automatically outside production.

Providers left unconfigured are simply unavailable (for example, a payment method without keys is rejected at checkout). For QA without provider sandboxes, set `PAYMENTS_ALLOW_SIMULATION=true` (ignored in production).

## Demo data

`php artisan migrate --seed` in `local` also seeds a demo dataset (set `SEED_DEMO_DATA=false` to skip). To manage it on its own:

```bash
php artisan wedpluz:demo           # seed (skips if present)
php artisan wedpluz:demo --reset   # remove demo data and seed again
php artisan wedpluz:demo --remove  # remove demo data only (real local accounts are kept)
```

It creates 23 accounts, 10 vendors with portfolios and reviews, and 13 events: live, featured, multi-day, online, free, approval-only, completed (with check-ins and a settlement), pending review and draft. It also adds 40 paid orders and 64 tickets bought through the real wallet checkout, wallets with ledger history, communities with stories and group chat, direct messages, notifications, banners, support tickets and a moderation report. Images are imported into Cloudinary under `<root>/demo/`. Nothing is emailed: demo addresses use the reserved `.test` domain, and jobs and mail are faked while seeding. It never runs in production.

| Account | Email | Notes |
|---|---|---|
| Customer | `customer@demo.wedpluz.test` | tickets, wallet (PIN `1234`), chats, saved items |
| Organiser (Premium) | `ada@demo.wedpluz.test` | 7 events, team, vendors, tasks, requests, settlement |
| Organiser (Basic) | `tunde@demo.wedpluz.test` | 4 events, manager on Ada's wedding |
| Organiser (unverified) | `kemi@demo.wedpluz.test` | pending-review and draft events |
| Vendor | `v_decor@demo.wedpluz.test` | Bloom Décor (other vendors: `v_photo@`, `v_catering@`, …) |
| Admin console | `admin@demo.wedpluz.test` | TOTP secret `WEDPLUZDEMOADMIN` |

Password for every demo account: `Wedpluz@2026`. Promo code `AFRO10` works on *Afrobeats Under The Stars*.

## Tests

```bash
createdb wedpluz_test            # once
php artisan test                 # 72 tests: unit, integration (HTTP-faked providers), feature
vendor/bin/pint                  # code style
```

Tests run against a real PostgreSQL database (`wedpluz_test`, configured in `phpunit.xml` and `.env.testing`) and never call live providers.

## Project layout

| Path | Contents |
|---|---|
| `app/Http/Controllers/Api/V1` | Thin controllers (customer, public, `Admin/`) |
| `app/Http/Requests` | Form Requests (camelCase fields, contract error format) |
| `app/Services` | Domain logic by module: Auth, Events, Checkout, Payments, Finance, Wallet, Messaging, Communities, Notifications, Admin, … |
| `app/Integrations` | Provider adapters: Paystack, Flutterwave, Monnify, Stripe, PayPal, ZeptoMail, Termii, Twilio, WhatsApp, FCM, Cloudinary, Prembly, Google, LiveKit, Anthropic |
| `app/Jobs` | Queued work: webhooks, payouts, withdrawals, notifications, exports, identity checks |
| `routes/api*.php` | Route map: public, customer, admin (permission per route), webhooks |
| `routes/channels.php` | Realtime channel authorisation |
| `routes/console.php` | Artisan commands and the schedule |
| `database/migrations` | PostgreSQL schema |

## Documentation

| Document | What it covers |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Request lifecycle, modules, auth, checkout, ledger, queues, scheduler, realtime, safeguards |
| [docs/database-schema.md](docs/database-schema.md) | Conventions, domains, relationships, DB-enforced integrity rules, full table reference |
| [docs/api-documentation.yaml](docs/api-documentation.yaml) | OpenAPI 3.1 for the implemented API |
| [docs/integrations.md](docs/integrations.md) | Each provider, webhooks and verification, failover, key ownership |
| [docs/environment-variables.md](docs/environment-variables.md) | Every variable, default and production requirement |
| [docs/deployment.md](docs/deployment.md#backend-api) | Backend deployment, provider checklist, operations (frontend deployment above it) |
| [docs/testing.md](docs/testing.md#backend-api-tests) | Backend test suites and how to run them (frontend testing above it) |
| [docs/implementation-progress.md](docs/implementation-progress.md) | Contract coverage, test inventory, outstanding work |
| [docs/assumptions-and-decisions.md](docs/assumptions-and-decisions.md) | Decisions where the contract was open or silent |
| [docs/backend-handoff/](docs/backend-handoff/) | The frontend contract: endpoints, business rules, permissions, errors, realtime |

## Security

- Never commit `.env` files or provider keys; `.env.example` holds names only.
- Report vulnerabilities privately to the Wedpluz engineering team rather than in public issues.
