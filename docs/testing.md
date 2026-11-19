# Testing

| Layer | Tool | Location | Command |
|---|---|---|---|
| Unit and component | Vitest 5, Vue Test Utils, jsdom | `tests/unit/**/*.spec.ts` | `npm test`, `npm run test:watch`, `npm run test:coverage` |
| End-to-end | Playwright | `tests/e2e/*.spec.ts` | `npm run test:e2e` |
| Static | vue-tsc (strict), ESLint | whole repo | `npm run typecheck`, `npm run lint` |
| Contract | OpenAPI validator | `docs/backend-handoff/openapi.yaml` | `npm run openapi:validate` (also runs inside `npm test`) |

All tests run against the **mock API adapter**, so they need no backend, network access or provider credentials.

## Unit and component tests

| Area | Spec | What it pins down |
|---|---|---|
| Formatting | `utils/format.spec.ts` | Money in kobo to naira (compact K and M), design date copy ("7th Dec, 2026", ranges), text helpers |
| Sanitising | `utils/sanitize.spec.ts` | Rich-text allow-list: scripts, event handlers, `javascript:` links and styles are stripped; safe links are hardened |
| Files | `utils/files.spec.ts` | Upload MIME and size validation; CSV formula-injection guard for admin exports |
| Validation | `validations/schemas.spec.ts` | Registration, password rules, age gate, username, wallet withdrawal minimum and PIN, NUBAN account numbers, event schedule rules |
| HTTP errors | `services/errors.spec.ts` | Laravel error envelope to `ApiError`: field errors, domain codes, correlation IDs, network and cancel cases |
| Token storage | `services/tokenStorage.spec.ts` | Customer and admin sessions are isolated; `/admin/*` routing; expiry handling |
| Mock contract | `services/mock-contract.spec.ts` | Response envelopes, error codes, admin 2FA and token audience, server-computed checkout totals, promo errors, double-cancel conflict, audit logging |
| Checkout store | `stores/checkout.store.spec.ts` | Line inputs, server-driven steps (seats, menu), seat caps, fresh idempotency keys, promoter referral capture |
| Admin store | `stores/admin.store.spec.ts` | `can` and `canAny` permission helpers (usability layer) |
| Forms | `composables/useForm.spec.ts` | Zod validation, transforms, Laravel 422 mapping, toasts, double-submit guard, reset |
| Components | `components/UiButton.spec.ts`, `components/UiPagination.spec.ts` | Loading and disabled states, safe external links, router links, OTP input and paste, pagination ranges and accessibility |
| Handoff spec | `docs/openapi.spec.ts` | The spec validates; every operation has a status; every admin operation has a permission and admin auth; payment writes need `Idempotency-Key`; webhooks are backend-only |

### Windows note

`npm test` runs Vitest through `scripts/vitest.mjs`. Some Windows shells start Node with a lowercase drive letter (`c:\`), which makes Vitest load two copies of its runner, and every suite then fails with "Cannot read properties of undefined (reading 'config')". The launcher normalises the drive letter. Calling `npx vitest` directly from such a shell can show that error.

## End-to-end tests

| Spec | Scenarios |
|---|---|
| `discovery.spec.ts` | Home sections and the mock badge; events list to detail; event facts; vendor marketplace to profile; 404; guests redirected to login with `redirect` |
| `auth.spec.ts` | Wrong-password error from the API; client-side email validation; login returns to the requested page and the **session survives a reload** |
| `checkout.spec.ts` | Full paid checkout: tickets, attendee, server-quoted total, Paystack hosted redirect (mock gateway), verified return page; ticket QR rendering |
| `organizer.spec.ts` | Managed events list and overview; server-side ticket validation at check-in (manual code); create-event wizard |
| `admin.spec.ts` | Password then 2FA login; wrong 2FA rejected; a customer session cannot open the console; search kept in the URL; finance pages; no horizontal scroll on phones |

Projects: `chromium` (desktop, all tests) and `mobile` (Pixel 7, tests tagged `@mobile`). The main flow tests also fail on uncaught page errors.

### Running

```bash
npx playwright install chromium     # once
npm run test:e2e                    # starts `npm run dev` on port 5173 in mock mode
```

Overrides:

| Variable | Use |
|---|---|
| `E2E_PORT=5180` | Start the dev server on another port |
| `E2E_BASE_URL=http://localhost:5180` | Test an already-running dev server (no server is started) |
| `PLAYWRIGHT_CHROMIUM_EXECUTABLE=/path/to/chrome` | Use a specific Chromium build, e.g. when the bundled browser version does not match |

Reports and traces land in `playwright-report/` and `test-results/` (both git-ignored). CI retries failures twice and records a trace on the first retry.

## Manual QA checklist (live API)

Mock tests cannot cover these; run them on staging once the backend is available:

- [ ] Paystack, Stripe, PayPal and Monnify sandbox payments: success, failure, abandoned payment, webhook arriving before and after the redirect.
- [ ] Refund round trip to each provider; wallet refunds.
- [ ] Two scanners checking in the same ticket at once: exactly one succeeds.
- [ ] Token refresh after access-token expiry with several requests in flight.
- [ ] Admin permissions: an admin without a permission gets `403` from the API even when calling endpoints directly.
- [ ] Realtime: messages and notification badges update without reload once Reverb is enabled.
- [ ] Email, SMS, WhatsApp and push templates render with real data.
- [ ] Lighthouse on a mid-range Android over throttled 3G: home, events and event detail.

## Results at handoff

| Check | Result |
|---|---|
| `npm run typecheck` | passing |
| `npm run lint` | passing (0 problems) |
| `npm test` | 13 files, 107 tests passing |
| `npm run test:e2e` | 24 tests passing (20 desktop, 4 mobile) |
| `npm run openapi:validate` | valid OpenAPI 3.1.0: 333 operations, 1,640 examples checked against their schemas |

---

# Backend API tests

The sections above cover the frontend. The Laravel API in this repository is tested with **Pest** against a real **PostgreSQL** database, because correctness depends on PostgreSQL behaviour: row locks, partial unique indexes, check constraints, `jsonb` and `tsvector`.

| Suite | Location | What it covers |
|---|---|---|
| Unit | `tests/Unit` | Money arithmetic, the rich-text sanitiser, TOTP (RFC 6238 test vectors, drift window), signed ticket QR payloads |
| Integration | `tests/Integration` | Provider adapters against HTTP fakes: Termii → Twilio failover (and no failover on invalid numbers), Stripe signature, tolerance and dedupe, WhatsApp signature and handshake |
| Feature | `tests/Feature` | HTTP flows through routing, middleware, validation, services and the database |

Feature coverage by area:

| Area | File | Highlights |
|---|---|---|
| Auth | `Feature/Auth/AuthenticationTest.php` | registration and next step, camelCase validation errors, case-insensitive duplicates, login and suspended accounts, email OTP with attempt limit, non-enumerating forgot password, reset revokes sessions, refresh rotation and reuse detection, correlation id, audience separation, login rate limit |
| Checkout | `Feature/Checkout/CheckoutTest.php` | server-side pricing and holds, never oversell, organiser self-purchase and per-order limits, unverified email block, free orders, paid flow with server-side verification, idempotent order replay and key reuse, amount mismatch, webhook signature + dedupe + fulfilment, expired quotes |
| Tickets | `Feature/Checkout/TicketLifecycleTest.php` | check-in exactly once, scanner permissions, refund of subtotal outside the window, no refund inside it, no cancelling a checked-in ticket |
| Wallet | `Feature/Wallet/WalletTest.php` | activation with OTP, identity required to withdraw, withdrawal settles or re-credits on failure, balance and PIN lockout, paying for tickets from the wallet |
| Organizer | `Feature/Events/OrganizerEventTest.php` | drafts and sanitised rich text, drafts hidden from discovery, publish validation, review routing, Premium gating (402), partial manager permissions, manager invitation |
| Admin | `Feature/Admin/AdminTest.php` | password + TOTP login with replay protection, audience separation, RBAC denials logged, suspension + audit trail, event approval, four-eyes payouts with fresh TOTP, role escalation guard, reports, dashboard |
| Social | `Feature/Social/MessagingCommunityTest.php` | direct conversations, idempotent sends by `clientId`, message requests and the unread badge, participant isolation, communities and invite codes, owner-only settings |
| Platform | `Feature/Platform/*` | scheduler tasks (order expiry with provider re-check, event completion → settlement) and broadcast channel authorisation |

## Running

```bash
# one-time: create the test database (connection settings come from phpunit.xml and .env.testing)
createdb -U postgres wedpluz_test

php artisan test                       # or vendor/bin/pest
vendor/bin/pest tests/Feature/Checkout
vendor/bin/pest --filter="four-eyes"
```

`phpunit.xml` selects the `wedpluz_test` database and sets the queue to `sync`, mail and cache to `array`, and the Reverb broadcaster with dummy keys (no network). `tests/TestCase.php` blocks stray HTTP (`Http::preventStrayRequests()`), fakes mail, turns realtime emission off, and provides helpers: `customer()`, `asCustomer()`, `adminUser()`, `asAdmin()`, `liveEvent()`, `configurePaystack()`.

**Never point the tests at a real database.** `LazilyRefreshDatabase` migrates and wraps each test in a transaction. `.env.testing` must name the test database so that `php artisan … --env=testing` cannot touch development data.

## Writing tests

- Prefer feature tests through HTTP, and assert on the contract shape (`data`, `meta`, `code`) rather than internals.
- Fake providers with `Http::fake()` using real response shapes; never call live providers from tests.
- For money flows, assert the ledger (`ledger_entries`) and wallet balances as well as the API response.
- Every bug fix gets a regression test.
