<?php

/*
| Wedpluz domain configuration. Runtime-tunable business values (fees, refund
| window, withdrawal limits...) live in PlatformSettings (admin-editable);
| the values here are infrastructure defaults and fallbacks.
*/

return [
    'web_origin' => env('WEDPLUZ_WEB_ORIGIN', 'http://localhost:5173'),
    'admin_origin' => env('WEDPLUZ_ADMIN_ORIGIN', 'http://localhost:5174'),
    'timezone' => env('WEDPLUZ_TIMEZONE', 'Africa/Lagos'),
    'default_currency' => env('WEDPLUZ_DEFAULT_CURRENCY', 'NGN'),

    'auth' => [
        'customer_access_ttl_minutes' => (int) env('AUTH_CUSTOMER_ACCESS_TTL', 60),
        'customer_refresh_ttl_days' => (int) env('AUTH_CUSTOMER_REFRESH_TTL_DAYS', 30),
        'admin_access_ttl_minutes' => (int) env('AUTH_ADMIN_ACCESS_TTL', 480),
        'admin_idle_timeout_minutes' => (int) env('AUTH_ADMIN_IDLE_TIMEOUT', 30),
        'admin_invite_ttl_hours' => (int) env('AUTH_ADMIN_INVITE_TTL_HOURS', 72),
        'minimum_age' => 13,
        // Accepted ID-token audiences (web, Android, iOS clients); the OAuth web client is always included.
        'google_client_ids' => array_values(array_unique(array_filter(array_map('trim', array_merge(
            explode(',', (string) env('GOOGLE_CLIENT_IDS', '')),
            [(string) env('GOOGLE_CLIENT_ID', '')],
        ))))),
        // Development only: accept this fixed OTP/2FA code. Never honoured when APP_ENV=production.
        'dev_fixed_otp' => env('AUTH_DEV_FIXED_OTP'),
        // Production only: reject passwords found in public breach corpora (HIBP k-anonymity range API).
        'check_breached_passwords' => (bool) env('AUTH_CHECK_BREACHED_PASSWORDS', true),
    ],

    'otp' => [
        'length' => 4,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],

    'wallet' => [
        'pin_max_attempts' => 5,
        'pin_lock_minutes' => 30,
        'max_bank_accounts' => 3,
        'daily_withdrawal_limit' => (int) env('WALLET_DAILY_WITHDRAWAL_LIMIT', 50000000),
        'payout_provider' => env('WALLET_PAYOUT_PROVIDER', 'paystack'),
    ],

    'checkout' => [
        'hold_minutes' => (int) env('CHECKOUT_HOLD_MINUTES', 10),
        'qr_prefix' => 'WP1',
    ],

    'payouts' => [
        'bank_change_cooldown_hours' => 48,
        'provider' => env('PAYOUT_PROVIDER', 'paystack'),
    ],

    'account_deletion' => [
        'grace_days' => 30,
    ],

    'uploads' => [
        'unattached_ttl_hours' => 24,
        // purpose => [max kilobytes, allowed mime prefixes/types, storage: public|private]
        'purposes' => [
            'avatar' => ['max_kb' => 5120, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'storage' => 'public'],
            'cover' => ['max_kb' => 10240, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'storage' => 'public'],
            'event_cover' => ['max_kb' => 10240, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'storage' => 'public'],
            'event_gallery' => ['max_kb' => 10240, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'storage' => 'public'],
            'vendor_work' => ['max_kb' => 51200, 'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm'], 'storage' => 'public'],
            'message_attachment' => ['max_kb' => 51200, 'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'video/webm', 'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/webm', 'audio/wav', 'application/pdf', 'application/zip', 'text/plain', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/msword', 'application/vnd.ms-excel'], 'storage' => 'public'],
            'status' => ['max_kb' => 51200, 'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm'], 'storage' => 'public'],
            'identity_selfie' => ['max_kb' => 10240, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'storage' => 'private'],
            'task_attachment' => ['max_kb' => 20480, 'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'storage' => 'public'],
            'planner_reference' => ['max_kb' => 20480, 'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], 'storage' => 'public'],
            'document' => ['max_kb' => 20480, 'mimes' => ['image/jpeg', 'image/png', 'application/pdf'], 'storage' => 'private'],
        ],
    ],

    'payments' => [
        // Non-production only: lets QA complete checkouts without a provider (ignored when APP_ENV=production).
        'allow_simulation' => (bool) env('PAYMENTS_ALLOW_SIMULATION', false),
    ],

    'refunds' => [
        // Refunds at or above this amount (kobo) wait for admin approval (bookings.manage).
        'approval_threshold' => (int) env('REFUND_APPROVAL_THRESHOLD', 50000000),
    ],

    // Fallback platform commission when no commission rule matches (admin-editable rules take precedence).
    'default_commission_percent' => (float) env('DEFAULT_COMMISSION_PERCENT', 5),

    // Explicit override; otherwise the placeholder uploaded by `php artisan wedpluz:cloudinary-assets` is used.
    'placeholder_cover' => env('EVENT_PLACEHOLDER_COVER'),

    /*
    | Image delivery (Cloudinary). Every image URL in API responses is rewritten
    | by the OptimizeImageUrls middleware: Cloudinary images get the preset for
    | their field (existing transformations are replaced), remote images are
    | served through Cloudinary fetch. All presets start with f_auto,q_auto
    | (best format and quality per browser). Crops use g_auto / g_face.
    */
    'images' => [
        'presets' => [
            'avatar' => 'f_auto,q_auto,c_fill,g_face,w_256,h_256',
            'cover' => 'f_auto,q_auto,c_limit,w_1600,h_1600',
            'image' => 'f_auto,q_auto,c_limit,w_1200,h_1200',
            'thumbnail' => 'f_auto,q_auto,c_fill,g_auto,w_480,h_480',
            'gallery' => 'f_auto,q_auto,c_limit,w_1600,h_1600',
            'logo' => 'f_auto,q_auto,c_limit,w_400,h_400',
            'media' => 'f_auto,q_auto,c_limit,w_2048,h_2048',
            'email' => 'f_jpg,q_auto,c_limit,w_1200',
            // Link previews (Open Graph / X cards): JPEG for the widest bot support, 1.91:1 crop.
            'og' => 'f_jpg,q_auto,c_fill,g_auto,w_1200,h_630',
        ],
        // JSON field => preset. Remote (non-Cloudinary) URLs are only fetched for these fields.
        'fields' => [
            'avatarUrl' => 'avatar',
            'coverUrl' => 'cover',
            'imageUrl' => 'image',
            'thumbnailUrl' => 'thumbnail',
            'gallery' => 'gallery',
            'logoUrl' => 'logo',
            'posterUrl' => 'cover',
        ],
    ],

    // Feature => minimum plan tier (free | premium). Overrides PlanGate::FEATURES.
    'plan_features' => [],

    'geo' => [
        // Request header carrying the visitor's ISO country code, set by the CDN/edge.
        'country_header' => env('GEO_COUNTRY_HEADER', 'CF-IPCountry'),
    ],

    'demo' => [
        // Demo data (php artisan wedpluz:demo) is refused in production unless this is true.
        'allow_in_production' => (bool) env('DEMO_DATA_IN_PRODUCTION', false),
        // Password for every demo account. Required in production, where it is never printed.
        'password' => env('DEMO_DATA_PASSWORD'),
    ],

    'health' => [
        // Required for GET /health/details outside local (header X-Health-Token).
        'token' => env('HEALTH_CHECK_TOKEN'),
    ],

    'realtime' => [
        'enabled' => env('BROADCAST_CONNECTION', 'null') !== 'null',
    ],
];
