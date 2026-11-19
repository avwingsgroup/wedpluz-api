# Implementation progress

Status of the Laravel API against the contract in [backend-handoff/](backend-handoff/) as of 18 November 2026.

## Summary

| Measure | Value |
|---|---|
| Contract operations (`openapi.yaml`) | 333 |
| Implemented | **321** (all 274 `required`, all 18 `optional`, 29 of 41 `proposed`) |
| Not implemented | 12 `proposed` social-graph operations (feed, posts, comments, follows) — not in the designs |
| Additive endpoints | 8 (admin invitation accept/confirm, Prembly and Flutterwave webhooks, signed downloads for ticket PDFs, receipts, private files, exports) |
| Registered `/api/v1` routes | 325 (212 customer/public, 105 admin, 8 webhook) |
| Automated tests | **72 passing** (372 assertions): 7 unit, 4 integration, 61 feature |
| Code style | Laravel Pint clean |

## Coverage by contract module

| Module | Implemented / contract | Notes |
|---|---|---|
| Auth | 10 / 10 | Email + password, Google, OTP, refresh rotation with reuse detection |
| Account & Profile | 27 / 27 | Profile, privacy, preferences, devices, sessions, interests, identity verification, account deletion |
| Event Discovery | 15 / 15 | Search (`tsvector` + filters), categories, suggestions, saved events, requests |
| Checkout & Tickets | 12 / 12 | Quotes, holds, seats, food, orders, verify, tickets, PDF, transfers, refunds |
| Organizer Portal | 36 / 36 | Event lifecycle, schedules, ticket types, sub-events, managers, vendors, tasks, guests, check-in, analytics, promotions |
| Vendors | 25 / 25 | Profiles, works, reviews, promos, saved vendors, contact, verification documents |
| Communities | 27 / 27 | Communities, members, invites, statuses, live sessions (LiveKit) |
| Messaging | 15 / 15 | Direct and community conversations, requests, reactions, stars, pins, media |
| Notifications | 6 / 6 | In-app list, unread count, actions |
| Wallet & Subscriptions | 18 / 18 | Wallet, PIN, top-up, withdrawals, bank accounts, plans, subscriptions |
| Pluz Planner | 16 / 16 | AI chat (Claude), menus, hall layouts, design generation, assets |
| System | 2 / 2 | Health, broadcasting auth |
| Webhooks | 10 / 10 | Paystack, Stripe, PayPal, Monnify, Flutterwave, Prembly (+ Primely alias), ZeptoMail, Termii, Twilio, WhatsApp |
| Admin (all sections) | 102 / 102 | Auth + 2FA, dashboard, users, events, organisers & vendors, bookings, finance, moderation, notifications, platform configuration, roles & audit, reports & exports, support |
| Proposed — social graph & feed | 0 / 12 | Not implemented; see decision #42 in [assumptions-and-decisions.md](assumptions-and-decisions.md) |

## Cross-cutting requirements

| Requirement | Status | Where |
|---|---|---|
| Contract envelopes, pagination, error body with `correlationId` | Done | `Controller`, presenters, `ApiExceptionRenderer` |
| camelCase validation errors with human field names | Done | `ApiRequest` |
| Idempotency keys on payment-sensitive writes | Done | `Idempotent` middleware |
| Rate limits with `Retry-After` | Done | `AppServiceProvider::configureRateLimiting` |
| CORS per contract §1.11 | Done | `config/cors.php` |
| Customer/admin token separation, refresh rotation, sessions | Done | `TokenService`, `EnsureAudience` |
| Admin TOTP, RBAC (32 permissions), audit and security logs | Done | `TwoFactorService`, `RequireAdminPermission`, `AuditLogger` |
| Server-side pricing, holds, no oversell, seat locking | Done | `CheckoutService`, `Inventory` |
| Provider re-verification before fulfilment; webhook signature + dedupe | Done | `WebhookService`, `WebhookRouter`, `FulfillmentService` |
| Double-entry ledger, wallet, settlements, four-eyes payouts | Done | `LedgerService`, `SettlementService`, `PayoutService` |
| Notifications across in-app, email, SMS, WhatsApp, push with preferences and dedupe | Done | `NotificationService`, `DeliverNotificationJob` |
| Realtime events and channel authorisation | Done (optional) | `Realtime`, `routes/channels.php` |
| Scheduler: expiry, completion, settlements, renewals, reminders, deletions, retention, reconciliation | Done | `ScheduledTasks`, `routes/console.php` |
| Data retention purges | Done | `ScheduledTasks::purge` |
| Rich-text sanitisation, EXIF stripping, private signed files | Done | `RichText`, `ImageSanitizer`, `UploadService` |
| OpenAPI for the implementation | Done | [api-documentation.yaml](api-documentation.yaml) |

## Test inventory

| Suite | Tests | Focus |
|---|---|---|
| Unit | 7 | Money, RichText, TOTP vectors and drift, ticket QR signing |
| Integration | 4 | SMS failover and no-failover, Stripe webhook signatures, WhatsApp signature and handshake |
| Feature — Auth | 14 | Registration, login, OTP, reset, refresh reuse, audience, rate limit |
| Feature — Checkout | 10 | Pricing, holds, oversell, idempotency, verification, amount mismatch, webhooks |
| Feature — Ticket lifecycle | 5 | Check-in, scanner permissions, refunds, cancellation rules |
| Feature — Wallet | 6 | Activation, withdrawals, failure re-credit, PIN lockout, wallet checkout |
| Feature — Organizer | 7 | Drafts, publish validation, review routing, plan gating, manager permissions |
| Feature — Admin | 9 | TOTP login, RBAC, suspension audit, approvals, four-eyes payouts, reports, dashboard |
| Feature — Social | 3 | Conversations, idempotent sends, requests and badges, communities |
| Feature — Platform | 7 | Scheduler tasks, broadcast channel authorisation |

Run with `php artisan test` (see [testing.md](testing.md#backend-api-tests)).

## Outstanding work

Ordered by launch impact.

1. **Live provider verification.** Every provider adapter is exercised against recorded-shape HTTP fakes, not real sandboxes. Before launch, run the sandbox checklist in [deployment.md](deployment.md#b4-provider-configuration-checklist) for each enabled payment method, a transfer, ZeptoMail, Termii/Twilio, WhatsApp templates, FCM, Prembly and Cloudinary. Response-shape differences found there are adapter-only fixes.
2. **Wider automated coverage.** Vendors, planner, notifications/campaigns, moderation, uploads, subscriptions renewals and most admin sections are covered only indirectly. Add feature tests for each, plus a contract test that validates responses against `api-documentation.yaml`.
3. **Social graph & feed** (12 proposed operations) once product and design define ranking, privacy and moderation.
4. **Promo code management** endpoints and admin screens (the contract has none; codes work at checkout).
5. **Image generation provider** for planner designs: choose one and implement it behind `ImageGenerator`.
6. **Observability:** connect an error tracker (Sentry or similar) and metrics; the correlation id is already in every log line and error body.
7. **Load testing** of checkout under contention (hold and seat locking) and of campaign fan-out before large on-sales.
8. **Restrict trusted proxies** to the load balancer's addresses in production (`bootstrap/app.php` currently trusts all, which is only safe when the API is not directly reachable).
