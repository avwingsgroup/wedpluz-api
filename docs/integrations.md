# Integrations

Every external provider sits behind a small interface in `app/Integrations/`, bound in `App\Providers\IntegrationServiceProvider`. Services depend on the interface, never on a vendor SDK, so a provider can be swapped or faked in tests (`Http::fake()`), and an unconfigured provider fails in one predictable way.

All HTTP calls go through `ProviderHttp`, which:

- applies a per-provider timeout and retry policy (retries only idempotent reads and transient failures),
- writes an `integration_logs` row per call: provider, operation, status, latency, and **redacted** request/response bodies (credentials, OTPs, ID and account numbers, card data, images, message bodies, phone numbers and email addresses are masked),
- turns network errors, timeouts, 5xx and 429 into a *transient* `IntegrationException`, and 4xx into a *permanent* one.

When a provider is not configured, the call throws `IntegrationException::notConfigured()`. An uncaught integration failure reaches clients as `503 SERVER_ERROR` (unconfigured or transient, with `Retry-After`) or `502 SERVER_ERROR` (permanent), with a generic message — provider error text is never returned to clients.

The admin **gateway health** panel (`GET /admin/dashboard` and `GET /admin/finance/gateways`) is computed from `integration_logs`: success rate and p95 latency per provider over the last 24 hours.

---

## Status summary

| Provider | Purpose | Status | Verified against |
|---|---|---|---|
| Paystack | Card/bank/USSD payments, refunds, transfers (payouts, withdrawals), bank list, account resolution | Implemented | Mocked HTTP in tests; live sandbox keys needed |
| Flutterwave | Payments, refunds, transfers, banks | Implemented (additive) | Mocked |
| Monnify | Payments (bank transfer), refunds, disbursements | Implemented | Mocked |
| Stripe | International cards, Google Pay (via Checkout) | Implemented | Mocked + signature test |
| PayPal | International payments | Implemented | Mocked |
| ZeptoMail | Transactional and campaign email | Implemented (Laravel mail transport) | Mocked |
| Termii | SMS (primary) | Implemented | Mocked + failover test |
| Twilio | SMS (fallback) | Implemented | Mocked + failover test |
| WhatsApp Business (Meta Cloud API) | Template notifications | Implemented | Signature + handshake test |
| Firebase Cloud Messaging (HTTP v1) | Push to Android/iOS/web | Implemented | Mocked |
| Cloudinary | Public media (avatars, covers, galleries, works, statuses) | Implemented | Mocked |
| Amazon S3 (or compatible) | Private files (identity selfies, documents, exports) | Implemented (Flysystem) | Local disk in tests |
| Prembly (IdentityPass) | Identity verification (NIN, BVN, passport, driver's licence + face match) | Implemented | Mocked |
| Google Sign-In | ID token verification | Implemented (JWKS, no SDK) | Mocked |
| LiveKit | Community live sessions (join tokens) | Implemented (token issuing) | Unit-level |
| Anthropic Claude | Pluz Planner AI chat | Implemented (official PHP SDK) | Not called in tests |
| Image generation | Planner "generate design" | Generic HTTP adapter; provider not chosen | Not called in tests |

"Mocked" means the request/response mapping is exercised with recorded-shape fakes; each provider still needs a sandbox smoke test with real keys before launch (see [deployment.md](deployment.md#backend-api)).

---

## 1. Payments

**Interfaces:** `PaymentGateway` (initialize, verify, refund, verifyWebhook, parseWebhook) and `TransferGateway` (banks, resolveAccount, transfer). `PaymentManager` resolves a gateway from the order's payment method.

| Method (`paymentMethod`) | Gateway | Currencies |
|---|---|---|
| `paystack`, `card` | Paystack | NGN, USD |
| `flutterwave` | Flutterwave | NGN, USD, GBP, EUR |
| `monnify` | Monnify | NGN |
| `stripe` | Stripe Checkout | USD, GBP, EUR, NGN |
| `google_pay` | Stripe Checkout (Google Pay enabled in the Stripe dashboard) | as Stripe |
| `paypal` | PayPal Orders v2 | USD, GBP, EUR |
| `wallet` | internal ledger | NGN |
| `free` | no provider; tickets issued immediately | — |

**Flow:** the API creates a `payments` row with our own reference, calls `initialize`, and returns the provider's checkout URL (or client params). Fulfilment happens **only** after a server-side `verify` call confirms success, amount and currency — triggered by the webhook or by `GET /orders/{id}/verify-payment` from the return page. Webhook bodies are never trusted on their own.

**Webhooks** (`POST /api/v1/webhooks/{provider}`):

| Provider | Verification | Events handled |
|---|---|---|
| Paystack | `x-paystack-signature` = HMAC-SHA512(body, secret key) | `charge.success`, `charge.failed`, `charge.dispute.create`, `transfer.success/failed/reversed`, `refund.processed/failed` |
| Flutterwave | `verif-hash` header equals `FLUTTERWAVE_SECRET_HASH` | `charge.completed`, `transfer.completed`, refund events |
| Monnify | `monnify-signature` = HMAC-SHA512(body, secret key) | `SUCCESSFUL_TRANSACTION`, `FAILED_TRANSACTION`, `SUCCESSFUL/FAILED/REVERSED_DISBURSEMENT`, `SUCCESSFUL/FAILED_REFUND` |
| Stripe | `Stripe-Signature` `t=…,v1=…` HMAC-SHA256, 5-minute tolerance | `checkout.session.completed/expired/async_payment_*`, `payment_intent.succeeded/payment_failed`, `charge.refunded`, `refund.updated`, `charge.dispute.created` |
| PayPal | PayPal `verify-webhook-signature` API with `PAYPAL_WEBHOOK_ID` | `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED/DECLINED`, refund events |

Processing: verify → store in `webhook_events` (unique per provider event id; duplicates return `200` and stop) → `ProcessWebhookJob` on the `critical` queue → `WebhookRouter` re-queries the provider → fulfilment, refund or transfer state change. Signature failures are logged to `security_logs` and return `401`.

**Transfers** (wallet withdrawals and organiser payouts) use `WALLET_PAYOUT_PROVIDER` / `PAYOUT_PROVIDER` (default Paystack). Bank accounts are resolved with the provider's account-name lookup before they are saved.

**Simulation:** outside production, `PAYMENTS_ALLOW_SIMULATION=true` lets QA complete a checkout without a provider. It is ignored when `APP_ENV=production`.

## 2. Email — ZeptoMail

A custom Symfony mailer transport (`zeptomail` mailer, `MAIL_MAILER=zeptomail`) posts to the ZeptoMail v1.1 API with the `Zoho-enczapikey` token (`ZEPTOMAIL_TOKEN`, or `MAIL_PASSWORD` when `MAIL_HOST` is a ZeptoMail host). Prefer it over SMTP: it reports per-message errors, and a Send Mail token can be accepted by the API while ZeptoMail's SMTP login rejects it. The API endpoint is regional — use `ZEPTOMAIL_ENDPOINT` with `api.zeptomail.eu`, `.in`, etc. for accounts outside the US data centre. All mail (OTP, receipts with PDF attachments, ticket emails, reminders, campaigns, admin invitations) goes through Laravel's mailer, so local development can use `MAIL_MAILER=log`.

**Webhook** `POST /webhooks/zeptomail?key=<ZEPTOMAIL_WEBHOOK_KEY>`: hard bounces and spam complaints add the address to `email_suppressions`; suppressed addresses are skipped for non-transactional mail.

## 3. SMS — Termii primary, Twilio fallback

`FailoverSmsSender` wraps the primary (`SMS_PRIMARY_PROVIDER`, default Termii) and fallback (`SMS_FALLBACK_PROVIDER`, default Twilio). A transient failure on the primary (outage, timeout, balance, auth, sender ID) is retried once on the fallback and logged as `sms.failover`. An **invalid recipient** is permanent and is not retried on the fallback, so a bad number is not charged twice.

SMS is used for OTP (when the phone is verified and the `smsOtpEnabled` feature is on), security alerts, and notifications the user opted into.

**Webhooks:** `POST /webhooks/termii?secret=<TERMII_WEBHOOK_SECRET>` and `POST /webhooks/twilio` (`X-Twilio-Signature` HMAC-SHA1) update delivery status.

## 4. WhatsApp Business

Meta Cloud API (`graph.facebook.com/{version}/{phone_number_id}/messages`). Notifications use **pre-approved templates** (`whatsappTemplate` on the notification template), as Meta requires outside the 24-hour customer-service window.

**Webhooks:** `GET /webhooks/whatsapp` answers the subscription handshake with `WHATSAPP_VERIFY_TOKEN`; `POST /webhooks/whatsapp` is verified with `X-Hub-Signature-256` (HMAC-SHA256 of the body with `WHATSAPP_APP_SECRET`) and records delivery status.

## 5. Push — Firebase Cloud Messaging

HTTP v1 API, authenticated with a service-account JWT exchanged for an OAuth access token (cached until expiry). Devices register through `POST /me/devices` (`android`, `ios`, `web`). Tokens that FCM reports as `UNREGISTERED`/`NOT_FOUND` are deleted automatically. `FCM_CREDENTIALS` accepts a file path, raw JSON or base64 JSON, so it works with secret managers.

## 6. Media — Cloudinary and S3

- **Public media** is uploaded through `POST /uploads` (multipart). The API validates size and MIME type per *purpose* (`config/wedpluz.php` → `uploads.purposes`), strips EXIF/GPS from JPEG and WebP, then stores the file on Cloudinary (folder `<CLOUDINARY_FOLDER or CLOUDINARY_ROOT_FOLDER>/<purpose>`). Image originals are capped on ingest by an incoming transformation (`CLOUDINARY_INCOMING_TRANSFORMATION`, default `c_limit,w_2560,h_2560`). Uploads are unattached until a resource consumes them; unattached uploads are purged after 24 hours.
- **Delivery: every image is optimised by Cloudinary.** The `OptimizeImageUrls` middleware rewrites image URLs in every API response, chosen by field (`config/wedpluz.php` → `images`): `avatarUrl` → `c_fill,g_face,256×256`; `coverUrl` → `c_limit,1600`; `imageUrl` → `c_limit,1200`; `thumbnailUrl` → `c_fill,g_auto,480×480`; `gallery` → `c_limit,1600`; any other Cloudinary image (media and attachment `url`s) → `c_limit,2048`. All presets add `f_auto,q_auto` (AVIF/WebP and quality per browser), and stored transformations are replaced rather than stacked, so changing a preset changes every image without touching data. Videos, raw files and local URLs pass through unchanged.
- **Remote images are imported.** Remote image URLs saved on a user avatar (for example a Google profile picture at sign-up) or a banner are copied into Cloudinary by `ImportRemoteImageJob` (`low` queue), and the column is repointed at the copy. Cloudinary *fetch* delivery is also supported (`CLOUDINARY_FETCH_REMOTE=true`) but is off by default because new Cloudinary accounts restrict "Fetched URL" (Settings → Security).
- **Static assets:** `php artisan wedpluz:cloudinary-assets` generates the event placeholder cover and uploads it to `<root>/static/event-placeholder`; `--check` only verifies the credentials (Admin API ping).
- **Private files** (`identity_selfie`, `document`, exports) go to the `private` disk — local in development, S3 (`PRIVATE_DISK_DRIVER=s3`) in production — and are only served through 15-minute signed URLs.

## 7. Identity — Prembly (IdentityPass)

`POST /me/identity-verification` stores the selfie privately and queues `VerifyIdentityJob`, which calls Prembly's document-plus-face endpoint for the ID type:

| ID type | Default endpoint |
|---|---|
| `nin` | `/identitypass/verification/biometrics/face/nin_w_face` |
| `bvn` | `/identitypass/verification/bvn_w_face` |
| `passport` | `/identitypass/verification/national_passport_with_face` |
| `drivers_license` | `/identitypass/verification/drivers_license/image` |

Paths can be overridden with `PREMBLY_ENDPOINT_*` (and `resident_card` added) without a code change. A face-match score below `PREMBLY_FACE_MATCH_THRESHOLD` (default 80) fails the check. ID types with no endpoint go to **manual review**: an admin alert is raised and an admin with `organizers.verify` decides. The result updates the user's `identityStatus` and emits `identity.updated`. Selfies are purged 90 days after the decision.

**Webhook** `POST /webhooks/prembly` (alias `/webhooks/primely`, the name used in the contract): HMAC-SHA512 of the body with `PREMBLY_WEBHOOK_SECRET`.

## 8. Google Sign-In

`POST /auth/social` (provider `google`) accepts an ID token from Google Identity Services (web) or the native SDKs. The token is verified locally against Google's JWKS (cached), and `iss`, `aud` (must be one of `GOOGLE_CLIENT_IDS`), `exp` and `email_verified` are checked. No Google client secret is needed on the server.

## 9. LiveKit

`POST /live/{id}/join` issues a short-lived LiveKit access token (HS256 JWT with room grants; publish rights only for the host and approved speakers). The client connects to `LIVEKIT_URL` directly. Live sessions are also gated by the `liveSessionsEnabled` platform feature.

## 10. Pluz Planner AI — Anthropic Claude

`PlannerAssistant` uses the official Anthropic PHP SDK (`anthropic-ai/sdk`) with model `ANTHROPIC_MODEL` (default `claude-opus-5-5`) and adaptive effort `ANTHROPIC_EFFORT`. The system prompt scopes the assistant to event planning for the Nigerian market. Conversation history is stored in `planner_chats` / `planner_chat_messages`. Refusals and provider errors are turned into friendly assistant messages, never raw errors. The feature is gated by the `plannerAiEnabled` feature flag and a premium plan.

## 11. Design generation

`GenerateDesignJob` (low queue) calls `ImageGenerator`, a generic HTTP adapter: `POST IMAGE_GENERATION_ENDPOINT` with `{ type, prompt, count }` and a bearer key, expecting `{ urls: [...] }`. The contract left the image provider open; once one is chosen, only this adapter changes. Without an endpoint and key (or with the `plannerAiEnabled` feature off), `POST /planner/generations` returns `403` "Design generation is not available yet."; provider failures mark the generation record `failed`.

## 12. Realtime — Laravel Reverb

Optional. With `BROADCAST_CONNECTION=reverb`, services broadcast through the queued `RealtimeBroadcast` event; Reverb fans out to clients over the Pusher protocol. Channel authorisation is `POST /api/v1/broadcasting/auth` with a customer or admin bearer token. See [architecture.md](architecture.md#9-realtime).

---

## Keys: who owns what

| Secret | Lives in | Never in |
|---|---|---|
| Provider secret keys, webhook secrets, Twilio auth token, WhatsApp token, FCM service account, Prembly key, Anthropic key, AWS keys, Reverb app secret | backend environment / secret manager | frontend builds, logs, git |
| Paystack/Stripe publishable keys, Reverb app key, Google client IDs | backend env (returned to clients where needed) and frontend env | — |

Rotating a key needs only an environment change and `php artisan config:cache` followed by a worker restart (`php artisan queue:restart`).
