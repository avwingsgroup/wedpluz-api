# Assumptions and decisions

Decisions taken while implementing the contract in [backend-handoff/](backend-handoff/), where the contract was silent, left a choice open, or marked something `proposed`. Each entry says what was decided, why, and how to change it. Additive changes stay within `/api/v1` as the contract allows (new fields, endpoints and enum values).

## Platform and stack

| # | Decision | Why | To change |
|---|---|---|---|
| 1 | **Laravel 13 on PHP 8.3+**, tested on PHP 8.5; no PHP 8.5-only syntax. | Latest Laravel; many hosts still run 8.3/8.4. | — |
| 2 | **PostgreSQL only.** Uses `pg_trgm`, `tsvector`, partial unique indexes, check constraints and `jsonb`. | The contract's data model asks for PostgreSQL; these features enforce inventory and money rules under concurrency. | — |
| 3 | **predis** is the default Redis client. | No PHP extension needed; switch with `REDIS_CLIENT=phpredis` for speed. | env |
| 4 | **Prefixed ULID primary keys** (`evt_01J…`) instead of integers or UUIDs. | The contract says IDs are opaque strings; prefixes make logs and support tickets readable; ULIDs sort by time. | — |
| 5 | **Presenters instead of Laravel API Resources.** | The contract has many viewer-dependent shapes; presenters batch-load relations per list to avoid N+1 queries. | — |
| 6 | **Dedicated `ScheduledTasks` service** behind thin artisan commands. | Commands, tests and manual runs share one implementation. | — |

## Authentication

| # | Decision | Why |
|---|---|---|
| 7 | Sanctum personal access tokens for both audiences, separated by token **ability** (`customer` / `admin`) and **model** (`User` / `AdminUser`), checked by `audience:` middleware. | One token system; a customer token can never reach `/admin/*` (401), and vice versa. |
| 8 | **Refresh tokens are our own table** (hashed, rotating, families with reuse detection). Sanctum has no refresh tokens. | Contract requires refresh with rotation. |
| 9 | A **session** (`/me/sessions`, admin session lists) is a refresh-token family: one sign-in on one device. Revoking a session revokes the family and its access tokens. | Matches what users understand as "signed-in devices". |
| 10 | The **admin idle timeout** (30 min) is enforced in Sanctum's token validation callback, before `last_used_at` is updated. | Doing it in middleware is too late: Sanctum bumps `last_used_at` first. |
| 11 | Admin TOTP is implemented in-house (RFC 6238, SHA-1, 6 digits, 30 s, ±1 step) with per-step replay protection. | Avoids a dependency for ~60 lines of well-specified code; verified against RFC test vectors. |
| 12 | **Admin invitations** get two additive endpoints: `POST /admin/auth/invitations/accept` (set password, receive TOTP secret) and `/confirm` (first TOTP code → tokens). | The contract has "invite admin" but no way for the invitee to finish onboarding. |
| 13 | OTP codes are **4 digits**, valid 10 minutes, 5 attempts, delivered by email (plus SMS when the phone is verified and `smsOtpEnabled` is on). `AUTH_DEV_FIXED_OTP` works only outside production. | Matches the designs' 4-box code input. |
| 14 | New passwords are checked against breach corpora (HIBP k-anonymity) **in production only**. | Avoids network calls in development and tests. |

## Payments and money

| # | Decision | Why |
|---|---|---|
| 15 | **Flutterwave** added as a payment provider and method; **`google_pay`** added as a method processed by **Stripe Checkout**. | Requested in the build brief; Google Pay has no standalone server API — Stripe surfaces it in Checkout. |
| 16 | The client's payment status is never trusted; only a provider **verify** call (from the webhook job or the return-page verify endpoint) fulfils an order. Webhook bodies trigger a re-query. | Contract business rules; prevents forged webhooks and return-URL tampering. |
| 17 | Provider amount or currency mismatch → order is **not** fulfilled, a critical system alert is raised, and finance resolves it. | Safer than auto-refunding a possibly legitimate payment. |
| 18 | A paid order that would oversell (race after the hold) is rolled back and **refunded automatically**. | Inventory integrity beats a manual oversell clean-up. |
| 19 | Unpaid orders are expired by the scheduler only **after one final provider check**. | A payment completed seconds before expiry is fulfilled, not lost. |
| 20 | **Refunds at or above `REFUND_APPROVAL_THRESHOLD`** (default ₦500,000) wait for admin approval; below it they go straight to the provider. | Contract allows refunds but doesn't set approval rules; large refunds are a fraud vector. |
| 21 | Buyer cancellations refund the **ticket subtotal, not the service fee**, and nothing inside the refund window (admin setting). | Business rules in the contract. |
| 22 | **Double-entry ledger** with string account names (`provider:paystack`, `wallet:<id>`, `organizer_payable:<userId>`, `platform:fees`, …). Wallet balances are cached columns updated in the same transaction, with a non-negative check constraint. | Auditable money movement; balances can be rebuilt from the ledger. |
| 23 | **Commission rules are versioned**: editing a rule deactivates it and creates a new one, and each order stores a snapshot of the rule that priced it (`commission_rule_snapshot`). | Historical orders and settlements must not change when rates change. |
| 24 | Settlements become eligible `settlementDelayDays` (default 3) after the event ends; open disputes or unverified identity hold them. | Contract rules, with the delay admin-editable. |
| 25 | **Four-eyes payouts**: the approver must differ from the requester (also a DB check constraint) and give a fresh TOTP code. | Contract requirement, defended in depth. |
| 26 | **Payment simulation** (`PAYMENTS_ALLOW_SIMULATION`) exists for QA only and is ignored in production. | Lets frontend QA run full flows without provider sandboxes. |
| 27 | Promo codes are modelled (`promo_codes`) and applied in quotes, but there is **no management endpoint** because the contract defines none. | Codes can be created by seeder or SQL until the admin UI is designed. |

## Events, tickets and organisers

| # | Decision | Why |
|---|---|---|
| 28 | `Event.menu` is derived from the event's linked **planner menu**; there is no separate menu-items table. | Avoids two sources of truth for the same data. |
| 29 | Unauthorised access to a managed event returns **404**, not 403. | Does not leak that a private event exists. |
| 30 | **Sub-events, planner AI and design generation require Premium** → `402 PAYMENT_REQUIRED`. | The designs show "Go Pro" on these features. |
| 31 | Publishing goes to **admin review** when the organiser is not identity-verified, it is their first paid event, the event is adult-only, or its category requires review; otherwise it goes live immediately. | Business rules; admins can still suspend live events. |
| 32 | `TICKETS_UNAVAILABLE` and `SEAT_TAKEN` use **409**. | The contract asked to pick one status and keep it consistent. |
| 33 | Ticket QR codes are `WP1.<ticketId>.<scheduleId>.<HMAC>` with a per-ticket secret version. Transfers and reissues bump the version so printed codes stop working. | Offline-verifiable format that the server still validates at check-in. |

## Identity verification

| # | Decision | Why |
|---|---|---|
| 34 | The identity provider is **Prembly (IdentityPass)**. The contract's `/webhooks/primely` is kept as an alias of `/webhooks/prembly`. | The contract said "Primely, pending confirmation"; Prembly was confirmed. |
| 35 | ID types Prembly cannot check automatically go to **manual review**: an admin alert is raised and an admin with `organizers.verify` decides. If Prembly is unconfigured or keeps failing, the job retries with backoff, then raises a "verification stuck" alert for the same manual path. | Organisers are never blocked by provider coverage or outages. |
| 36 | Selfies are stored on the private disk and **purged 90 days** after the decision. | Data-retention rules; minimises sensitive data held. |

## Messaging, communities and realtime

| # | Decision | Why |
|---|---|---|
| 37 | First messages between unconnected users arrive as **message requests** and do not count toward the unread badge until accepted. | Matches the designs' requests tab; limits spam. |
| 38 | Message sends are idempotent by `(sender, clientId)`. | Mobile clients retry on flaky networks. |
| 39 | **Realtime uses Laravel Reverb** and is optional; with `BROADCAST_CONNECTION=null` the API works fully and clients poll. Broadcasts are queued and dispatched after commit. | Contract recommendation; realtime is an enhancement, not a dependency. |
| 40 | Channel authorisation lives in `routes/channels.php` at `POST /api/v1/broadcasting/auth` and accepts both customer and admin tokens. Presence and private conversation channels share one rule. | Laravel strips `private-`/`presence-` prefixes before matching. |
| 41 | **Live sessions use LiveKit** (token issuing only) and are gated by the `liveSessionsEnabled` feature flag. | The contract left the streaming provider open. |
| 42 | **Social feed, posts, comments and follows** (12 `proposed` operations) are **not implemented**. | Marked proposed and absent from the designs; building them needs product decisions (ranking, moderation, privacy). |

## AI and media

| # | Decision | Why |
|---|---|---|
| 43 | Pluz Planner AI uses **Anthropic Claude** through the official PHP SDK (`claude-opus-5-5`, adaptive effort, server-side fallback enabled), gated by `plannerAiEnabled` and Premium. | The contract left the AI provider open; the official SDK is the supported path. |
| 44 | **Design generation** uses a generic HTTP adapter (`IMAGE_GENERATION_ENDPOINT`) because no image provider was chosen. | Swapping in a provider later touches one class. |
| 45 | Public media goes to **Cloudinary**; private files to the **private disk** (local or S3) with 15-minute signed URLs. JPEG/WebP EXIF (including GPS) is stripped before upload. | Privacy; Cloudinary handles transformations for public images. |

## Operations

| # | Decision | Why |
|---|---|---|
| 46 | Queues: `critical`, `notifications`, `broadcasts`, `default`, `low`, consumed in that priority order. | Money movement must not wait behind campaigns or exports. |
| 47 | Uncaught provider failures surface as `503`/`502 SERVER_ERROR` with a generic message; provider error text is never returned to clients. | `error-handling.md` maps upstream failures to 502/503; provider messages can leak internals. |
| 48 | CORS origins come from `CORS_ALLOWED_ORIGINS`; local Vite origins are added automatically outside production; credentials mode is off. | Contract §1.11 — tokens travel in the `Authorization` header. |
| 49 | Scheduled times are anchored to **Africa/Lagos** (e.g. reconciliation 03:00 WAT); storage is UTC. | Operators work in WAT. |
