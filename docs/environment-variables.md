# Environment variables

Every variable the API reads, with its default and whether production needs it. The template is [`.env.example`](../.env.example). Secrets belong in the deployment platform's secret store — never in git, frontend builds or logs.

After changing variables in production run `php artisan config:cache` and `php artisan queue:restart` (workers keep the old config until restarted).

**Legend** — **Req**: required in production. **Rec**: strongly recommended (a feature is off without it). **Opt**: optional. **Dev**: development or QA only.

## Application

| Variable | Req | Default | Notes |
|---|---|---|---|
| `APP_NAME` | Opt | `Wedpluz` | Used in emails and TOTP issuer. |
| `APP_ENV` | Req | `production` | `local`, `staging` or `production`. Production disables dev OTPs, payment simulation and debug output. |
| `APP_KEY` | Req | — | `php artisan key:generate`. Encrypts bank account numbers, TOTP secrets and signed URLs: **rotating it makes existing encrypted values unreadable**. |
| `APP_DEBUG` | Req | `false` | Must be `false` outside local development. |
| `APP_URL` | Req | `http://localhost` | Public API origin, e.g. `https://api.wedpluz.com`. Used for signed download URLs. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | Opt | `en` | |
| `APP_MAINTENANCE_DRIVER` | Opt | `file` | Use `cache` with several web nodes. |
| `BCRYPT_ROUNDS` | Opt | `12` | |
| `WEDPLUZ_WEB_ORIGIN` | Req | `http://localhost:5173` | Customer web app origin for links in emails, payment return URLs, sitemap `<loc>`s and share-page canonical links. |
| `WEDPLUZ_ADMIN_ORIGIN` | Req | `http://localhost:5174` | Admin console origin for invitation links. |
| `CORS_ALLOWED_ORIGINS` | Req | wedpluz.com, www, admin | Comma-separated. Local Vite origins are added automatically outside production. |
| `WEDPLUZ_TIMEZONE` | Opt | `Africa/Lagos` | Display timezone for emails, PDFs and reports. Storage is always UTC. |
| `WEDPLUZ_DEFAULT_CURRENCY` | Opt | `NGN` | |

## Logging

| Variable | Req | Default | Notes |
|---|---|---|---|
| `LOG_CHANNEL` | Opt | `stack` | |
| `LOG_STACK` | Opt | `single` | `daily` on VMs, `stderr` in containers. |
| `LOG_LEVEL` | Opt | `debug` | `info` in production. |
| `LOG_DEPRECATIONS_CHANNEL` | Opt | `null` | |

## Database and Redis

| Variable | Req | Default | Notes |
|---|---|---|---|
| `DB_CONNECTION` | Req | `pgsql` | PostgreSQL only (uses `pg_trgm`, `tsvector`, partial indexes, `jsonb`). |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Req | `127.0.0.1`, `5432`, `wedpluz` | The user needs `CREATE EXTENSION` once (or create `pg_trgm` beforehand). |
| `DB_SSLMODE` | Rec | `prefer` | `require` for managed databases. |
| `REDIS_CLIENT` | Opt | `predis` | `phpredis` if the extension is installed (faster). |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Req | `127.0.0.1`, `6379` | Cache, queues, scheduler locks, rate limits. |
| `CACHE_STORE` | Req | `redis` | Must be shared across nodes (locks, `onOneServer`). |
| `QUEUE_CONNECTION` | Req | `redis` | `sync` only in tests. Long jobs go to `redis-long` automatically. |
| `REDIS_QUEUE_DB` | Opt | `2` | Dedicated Redis DB for queues (cache uses `REDIS_CACHE_DB`, default 1). |
| `REDIS_QUEUE_RETRY_AFTER` / `REDIS_LONG_QUEUE_RETRY_AFTER` | Opt | `180` / `960` | Seconds before an unacknowledged job is retried; keep above the worker `--timeout`. |
| `QUEUE_LONG_CONNECTION` | Opt | `redis-long` | Connection for long-running jobs. |
| `HEALTH_CHECK_TOKEN` | Rec | — | Required header value (`X-Health-Token`) for `GET /health/details`; without it the endpoint is only open in `local`. |
| `SESSION_DRIVER` | Opt | `array` | The API is stateless. |

## Files

| Variable | Req | Default | Notes |
|---|---|---|---|
| `FILESYSTEM_DISK` | Opt | `local` | |
| `PRIVATE_DISK_DRIVER` | Req | `local` | `s3` in production — identity selfies, documents and exports must survive redeploys and never be public. |
| `PRIVATE_BUCKET` | Opt | `AWS_BUCKET` | A separate, non-public bucket is recommended. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | Req with S3 | — | |
| `AWS_URL`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | Opt | — | For S3-compatible stores (Cloudflare R2, DigitalOcean Spaces, MinIO). |

## Authentication

| Variable | Req | Default | Notes |
|---|---|---|---|
| `AUTH_CUSTOMER_ACCESS_TTL` | Opt | `60` | Minutes. |
| `AUTH_CUSTOMER_REFRESH_TTL_DAYS` | Opt | `30` | |
| `AUTH_ADMIN_ACCESS_TTL` | Opt | `480` | Minutes (absolute). |
| `AUTH_ADMIN_IDLE_TIMEOUT` | Opt | `30` | Minutes without a request before an admin token dies. |
| `AUTH_ADMIN_INVITE_TTL_HOURS` | Opt | `72` | |
| `AUTH_CHECK_BREACHED_PASSWORDS` | Opt | `true` | Production only; checks new passwords against the Have I Been Pwned range API (k-anonymity: only a 5-character hash prefix leaves the server). |
| `AUTH_DEV_FIXED_OTP` | Dev | — | A fixed code accepted in non-production for QA as any email/SMS OTP and as the admin 2FA code. Use 4 digits (customer OTPs are 4 digits; any non-matching admin code falls back to it). Ignored in production. |
| `GOOGLE_CLIENT_IDS` | Rec | `GOOGLE_CLIENT_ID` | Comma-separated client IDs whose ID tokens are accepted (web, Android, iOS). `GOOGLE_CLIENT_ID` is always included. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Rec | — | OAuth 2.0 web client for the redirect flow (`GET /auth/google/redirect` → `POST /auth/social` with `code`). The secret is used only server-to-server with Google. |
| `GOOGLE_REDIRECT_URI` | Rec | — | Default web callback, e.g. `https://wedpluz.com/auth/google/callback`. Must be an Authorized redirect URI of the client in Google Cloud Console. |
| `GOOGLE_REDIRECT_URIS` | Opt | — | Extra allowed callbacks (comma-separated), e.g. `http://localhost:5173/auth/google/callback` for local development. Each must also be registered in Google Cloud Console. |
| `ADMIN_BOOTSTRAP_EMAIL`, `ADMIN_BOOTSTRAP_PASSWORD` | First deploy | — | `php artisan db:seed` creates this super admin once and prints its TOTP secret. Remove afterwards. |

## Business defaults

Runtime business values (fees, refund window, settlement delay, feature flags) are **admin-editable Platform Settings** stored in the database. These variables are infrastructure defaults.

| Variable | Default | Notes |
|---|---|---|
| `CHECKOUT_HOLD_MINUTES` | `10` | How long a quote/hold reserves inventory. |
| `WALLET_DAILY_WITHDRAWAL_LIMIT` | `50000000` | Kobo (₦500,000). |
| `WALLET_PAYOUT_PROVIDER` | `paystack` | Transfer provider for wallet withdrawals: `paystack`, `flutterwave` or `monnify`. |
| `PAYOUT_PROVIDER` | `paystack` | Transfer provider for organiser payouts. |
| `REFUND_APPROVAL_THRESHOLD` | `50000000` | Kobo. Refunds at or above this wait for admin approval. |
| `DEFAULT_COMMISSION_PERCENT` | `5` | Used only when no commission rule matches. |
| `PAYMENTS_ALLOW_SIMULATION` | `false` | **Dev.** Lets QA complete checkouts without a provider; ignored in production. |
| `EVENT_PLACEHOLDER_COVER` | `<root>/static/event-placeholder` on Cloudinary | Cover image for events without one (uploaded by `wedpluz:cloudinary-assets`). |

## Email — ZeptoMail

| Variable | Req | Default | Notes |
|---|---|---|---|
| `MAIL_MAILER` | Req | `log` | `zeptomail` in staging/production. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Req | — | Domain must be verified in ZeptoMail (SPF, DKIM). |
| `ZEPTOMAIL_TOKEN` | Req | `MAIL_PASSWORD` if `MAIL_HOST` is ZeptoMail | Mail Agent "Send Mail" token, without the `Zoho-enczapikey` prefix. |
| `ZEPTOMAIL_ENDPOINT` | Opt | `https://api.zeptomail.com/v1.1/email` | Use the regional endpoint for your account (e.g. `.eu`). |
| `ZEPTOMAIL_BOUNCE_ADDRESS` | Opt | — | |
| `ZEPTOMAIL_WEBHOOK_KEY` | Rec | — | Shared secret on the webhook URL (`?key=`). Without it bounce webhooks are rejected. |

## Media — Cloudinary

| Variable | Req | Default |
|---|---|---|
| `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` | Req | — |
| `CLOUDINARY_FOLDER` (or `CLOUDINARY_ROOT_FOLDER`) | Opt | `wedpluz` |
| `CLOUDINARY_INCOMING_TRANSFORMATION` | Opt | `c_limit,w_2560,h_2560` — applied to image originals on upload |
| `CLOUDINARY_FETCH_REMOTE` | Opt | `false` — serve remote images via Cloudinary fetch; needs "Fetched URL" allowed in Cloudinary security settings |

Delivery presets per image field are in `config/wedpluz.php` → `images`. After setting the credentials run `php artisan wedpluz:cloudinary-assets` once to upload the placeholder cover.

## SMS — Termii and Twilio

| Variable | Req | Default | Notes |
|---|---|---|---|
| `SMS_PRIMARY_PROVIDER` / `SMS_FALLBACK_PROVIDER` | Opt | `termii` / `twilio` | |
| `TERMII_API_KEY` | Rec | — | |
| `TERMII_SENDER_ID` | Opt | `Wedpluz` | Must be approved by Termii. |
| `TERMII_CHANNEL` | Opt | `dnd` | `dnd` reaches DND-registered Nigerian numbers (needed for OTP). |
| `TERMII_BASE_URL` | Opt | `https://api.ng.termii.com` | |
| `TERMII_WEBHOOK_SECRET` | Opt | — | Delivery-report webhook (`?secret=`). |
| `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` | Rec | — | Auth token also verifies Twilio webhooks. |
| `TWILIO_MESSAGING_SERVICE_SID` or `TWILIO_FROM` | Rec | — | Messaging service preferred. |

## WhatsApp Business

| Variable | Req | Default | Notes |
|---|---|---|---|
| `WHATSAPP_ACCESS_TOKEN` | Opt | — | System-user permanent token. |
| `WHATSAPP_PHONE_NUMBER_ID` | Opt | — | |
| `WHATSAPP_APP_SECRET` | Opt | — | Verifies `X-Hub-Signature-256`. |
| `WHATSAPP_VERIFY_TOKEN` | Opt | — | Webhook subscription handshake. |
| `WHATSAPP_API_VERSION` | Opt | `v21.0` | |
| `WHATSAPP_TEMPLATE_LANGUAGE` | Opt | `en` | |

## Push — Firebase Cloud Messaging

| Variable | Req | Default | Notes |
|---|---|---|---|
| `FCM_PROJECT_ID` | Rec | — | |
| `FCM_CREDENTIALS` | Rec | — | Service-account JSON: file path, raw JSON or base64. |

## Identity — Prembly

| Variable | Req | Default | Notes |
|---|---|---|---|
| `PREMBLY_APP_ID`, `PREMBLY_API_KEY` | Rec | — | Organiser verification falls back to manual review without them. |
| `PREMBLY_BASE_URL` | Opt | `https://api.prembly.com` | |
| `PREMBLY_WEBHOOK_SECRET` | Rec | — | |
| `PREMBLY_FACE_MATCH_THRESHOLD` | Opt | `80` | |
| `PREMBLY_ENDPOINT_NIN`, `_BVN`, `_PASSPORT`, `_DRIVERS_LICENSE`, `_RESIDENT_CARD` | Opt | see [integrations.md](integrations.md#7-identity--prembly-identitypass) | Path overrides. |

## Payments

At least one NGN provider (Paystack, Flutterwave or Monnify) is required in production. A method whose provider is not configured is rejected at checkout.

| Variable | Default | Notes |
|---|---|---|
| `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY` | — | Secret key also verifies webhooks. |
| `PAYSTACK_BASE_URL` (or `PAYSTACK_PAYMENT_URL`) | `https://api.paystack.co` | |
| `FLUTTERWAVE_SECRET_KEY`, `FLUTTERWAVE_PUBLIC_KEY`, `FLUTTERWAVE_SECRET_HASH` | — | Secret hash = webhook `verif-hash`. |
| `FLUTTERWAVE_BASE_URL` | `https://api.flutterwave.com/v3` | |
| `MONNIFY_API_KEY`, `MONNIFY_SECRET_KEY`, `MONNIFY_CONTRACT_CODE` | — | |
| `MONNIFY_WALLET_ACCOUNT_NUMBER` | — | Source account for disbursements. |
| `MONNIFY_BASE_URL` | `https://sandbox.monnify.com` | `https://api.monnify.com` in production. |
| `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` | — | Enables `stripe` and `google_pay`. |
| `STRIPE_BASE_URL` | `https://api.stripe.com` | |
| `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID` | — | |
| `PAYPAL_BASE_URL` | `https://api-m.sandbox.paypal.com` | `https://api-m.paypal.com` in production. |

## Realtime — Laravel Reverb

| Variable | Default | Notes |
|---|---|---|
| `BROADCAST_CONNECTION` | `null` | `reverb` enables realtime; `null` disables it (clients poll). |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | — | The key is public (frontend `VITE_REALTIME_KEY`); the secret is not. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | — / `443` / `https` | Where the API publishes events (public WebSocket host). |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | `0.0.0.0` / `8080` | Where `php artisan reverb:start` listens. |
| `REVERB_ALLOWED_ORIGINS` | `*` | Hostnames allowed to open sockets, e.g. `wedpluz.com,admin.wedpluz.com`. |
| `REVERB_SCALING_ENABLED` | `false` | `true` with more than one Reverb node (Redis pub/sub). |

## Live sessions, AI and design generation

| Variable | Default | Notes |
|---|---|---|
| `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` | — | Live sessions also need the `liveSessionsEnabled` feature flag. |
| `ANTHROPIC_API_KEY` | — | Pluz Planner AI chat. Also needs the `plannerAiEnabled` feature flag. |
| `ANTHROPIC_MODEL` | `claude-opus-5-5` | |
| `ANTHROPIC_EFFORT` | `medium` | `low`, `medium` or `high`. |
| `ANTHROPIC_MAX_TOKENS` | `16000` | |
| `IMAGE_GENERATION_DRIVER` | — | Informational; the HTTP adapter is used when endpoint and key are set. |
| `IMAGE_GENERATION_ENDPOINT`, `IMAGE_GENERATION_API_KEY` | — | Design generation is disabled without both. |
