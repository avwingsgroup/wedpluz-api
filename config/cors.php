<?php

/*
| CORS (api-contracts.md §1.11). Production origins come from
| CORS_ALLOWED_ORIGINS (comma-separated: web, www and admin, plus staging
| hosts on staging). Local Vite dev servers are added outside production.
| Bearer tokens travel in the Authorization header, so credentials are off.
*/

// Parsed here, not via config('wedpluz.*'): config files load alphabetically.
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env(
    'CORS_ALLOWED_ORIGINS',
    'https://wedpluz.com,https://www.wedpluz.com,https://admin.wedpluz.com'
)))));
if (env('APP_ENV', 'production') !== 'production') {
    $origins = array_merge($origins, ['http://localhost:5173', 'http://localhost:5174', 'http://127.0.0.1:5173', 'http://127.0.0.1:5174']);
}

return [
    'paths' => ['api/*', 'up'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_unique($origins)),
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Authorization', 'Accept', 'Content-Type', 'X-Correlation-Id', 'X-Client-Platform',
        'X-Client-Version', 'Idempotency-Key', 'X-Requested-With', 'X-Socket-Id',
    ],
    'exposed_headers' => ['X-Correlation-Id', 'Retry-After', 'Content-Disposition'],
    'max_age' => 86400,
    'supports_credentials' => false,
];
