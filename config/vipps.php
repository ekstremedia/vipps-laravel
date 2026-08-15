<?php

declare(strict_types=1);

/*
 * Credentials come from the sales unit's developer section in the Vipps
 * merchant portal — one set per environment; test keys only work against the
 * test host and vice versa, so 'environment' switches host and MUST match
 * the keys.
 *
 * The timeouts are mandatory and enforced (the service provider refuses
 * non-positive values): Guzzle waits forever by default, and a payment call
 * with no deadline can wedge a queue worker for good.
 */
return [
    'client_id' => env('VIPPS_CLIENT_ID', ''),
    'client_secret' => env('VIPPS_CLIENT_SECRET', ''),
    'subscription_key' => env('VIPPS_SUBSCRIPTION_KEY', ''),
    'merchant_serial_number' => env('VIPPS_MERCHANT_SERIAL_NUMBER', ''),

    // 'test' (apitest.vipps.no) or 'production' (api.vipps.no).
    'environment' => env('VIPPS_ENVIRONMENT', 'test'),

    // Transport deadlines in seconds. Positive values required.
    'timeout' => (int) env('VIPPS_TIMEOUT', 15),
    'connect_timeout' => (int) env('VIPPS_CONNECT_TIMEOUT', 5),

    // Sent as Vipps-System-Name/-Version on every call; identifies your app
    // in Vipps' logs. Defaults resolve at runtime to the app name and the
    // Laravel version.
    'system' => [
        'name' => env('VIPPS_SYSTEM_NAME'),
        'version' => env('VIPPS_SYSTEM_VERSION'),
    ],

    // Secret returned ONCE by `php artisan vipps:webhooks register`. Empty
    // means "webhooks not configured": the signature middleware answers 404
    // so scanners cannot even confirm the endpoint exists.
    'webhook_secret' => env('VIPPS_WEBHOOK_SECRET', ''),

    // Cache store name for sharing access tokens between workers
    // (null = the app's default store).
    'token_cache_store' => env('VIPPS_TOKEN_CACHE_STORE'),

    // Vipps Login (OIDC / Socialite driver 'vipps').
    'login' => [
        'redirect' => env('VIPPS_LOGIN_REDIRECT_URI', ''),
        // Space-separated OIDC scopes requested by the Socialite driver.
        'scopes' => env('VIPPS_LOGIN_SCOPES', 'openid name email phoneNumber'),
    ],
];
