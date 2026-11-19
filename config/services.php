<?php

/*
| Third-party provider credentials. Every value comes from the environment;
| nothing here is ever returned by an API response.
*/

return [

    // ---- Email -------------------------------------------------------------
    'zeptomail' => [
        // The Send Mail token. Falls back to MAIL_PASSWORD when SMTP-style ZeptoMail credentials are configured.
        'token' => env('ZEPTOMAIL_TOKEN', str_contains((string) env('MAIL_HOST', ''), 'zeptomail') ? env('MAIL_PASSWORD') : null),
        'endpoint' => env('ZEPTOMAIL_ENDPOINT', 'https://api.zeptomail.com/v1.1/email'),
        'bounce_address' => env('ZEPTOMAIL_BOUNCE_ADDRESS'),
        'webhook_key' => env('ZEPTOMAIL_WEBHOOK_KEY'),
        'timeout' => 15,
    ],

    // ---- Media -------------------------------------------------------------
    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder' => env('CLOUDINARY_FOLDER', env('CLOUDINARY_ROOT_FOLDER', 'wedpluz')),
        // Applied on upload to images: caps stored originals (delivery transformations do the rest).
        'incoming_transformation' => env('CLOUDINARY_INCOMING_TRANSFORMATION', 'c_limit,w_2560,h_2560'),
        // Serve remote images through Cloudinary fetch delivery. Needs "Fetched URL" allowed in
        // Cloudinary Settings > Security (restricted by default on new accounts). Remote avatars and
        // banners are imported into Cloudinary on save either way.
        'fetch_remote' => (bool) env('CLOUDINARY_FETCH_REMOTE', false),
        'timeout' => 120,
    ],

    // ---- SMS ---------------------------------------------------------------
    'sms' => [
        'primary' => env('SMS_PRIMARY_PROVIDER', 'termii'),
        'fallback' => env('SMS_FALLBACK_PROVIDER', 'twilio'),
    ],

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'Wedpluz'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
        'channel' => env('TERMII_CHANNEL', 'dnd'),
        'webhook_secret' => env('TERMII_WEBHOOK_SECRET'),
        'timeout' => 10,
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'from' => env('TWILIO_FROM'),
        'timeout' => 10,
    ],

    // ---- WhatsApp Business (Meta Cloud API) --------------------------------
    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'default_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
        'timeout' => 15,
    ],

    // ---- Push (Firebase Cloud Messaging HTTP v1) ---------------------------
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        // Path to the service-account JSON, or the JSON itself (base64 allowed).
        'credentials' => env('FCM_CREDENTIALS'),
        'timeout' => 10,
    ],

    // ---- Identity (Prembly / IdentityPass) ---------------------------------
    'prembly' => [
        'app_id' => env('PREMBLY_APP_ID'),
        'api_key' => env('PREMBLY_API_KEY'),
        'base_url' => env('PREMBLY_BASE_URL', 'https://api.prembly.com'),
        'webhook_secret' => env('PREMBLY_WEBHOOK_SECRET'),
        'face_match_threshold' => (float) env('PREMBLY_FACE_MATCH_THRESHOLD', 80),
        // Override product routes if Prembly changes them (confirm in sandbox).
        'endpoints' => array_filter([
            'nin' => env('PREMBLY_ENDPOINT_NIN'),
            'bvn' => env('PREMBLY_ENDPOINT_BVN'),
            'passport' => env('PREMBLY_ENDPOINT_PASSPORT'),
            'drivers_license' => env('PREMBLY_ENDPOINT_DRIVERS_LICENSE'),
            'resident_card' => env('PREMBLY_ENDPOINT_RESIDENT_CARD'),
        ]),
        'timeout' => 30,
    ],

    // ---- Payments ----------------------------------------------------------
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co')),
        'timeout' => 20,
    ],

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
        'timeout' => 20,
    ],

    'monnify' => [
        'api_key' => env('MONNIFY_API_KEY'),
        'secret_key' => env('MONNIFY_SECRET_KEY'),
        'contract_code' => env('MONNIFY_CONTRACT_CODE'),
        'wallet_account_number' => env('MONNIFY_WALLET_ACCOUNT_NUMBER'),
        'base_url' => env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'),
        'timeout' => 20,
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com'),
        'webhook_tolerance' => 300,
        'timeout' => 20,
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'base_url' => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'),
        'timeout' => 20,
    ],

    // ---- Google identity ---------------------------------------------------
    'google' => [
        'certs_url' => 'https://www.googleapis.com/oauth2/v3/certs',
        'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
    ],

    // ---- Live streaming (LiveKit) ------------------------------------------
    'livekit' => [
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        'url' => env('LIVEKIT_URL'),
        'token_ttl' => 3600,
    ],

    // ---- AI (Pluz Planner) -------------------------------------------------
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
        'timeout' => 60,
    ],

    'image_generation' => [
        'driver' => env('IMAGE_GENERATION_DRIVER'),
        'endpoint' => env('IMAGE_GENERATION_ENDPOINT'),
        'api_key' => env('IMAGE_GENERATION_API_KEY'),
    ],
];
