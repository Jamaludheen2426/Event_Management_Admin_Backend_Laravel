<?php

declare(strict_types=1);

/*
| Client sign-in settings (v1 env names kept).
*/
return [

    // One-time sign-in codes sent to a phone.
    'otp_minutes' => 10,
    'otp_max_attempts' => 5,

    // Outside production only: return the code in the response (OTP_DEV_ECHO),
    // or accept any 6 digits (OTP_ACCEPT_ANY). Ignored in production.
    'otp_dev_echo' => (bool) env('OTP_DEV_ECHO', false),
    'otp_accept_any' => (bool) env('OTP_ACCEPT_ANY', false),

    // Social sign-in: the API's public base URL for provider callbacks, and the
    // public-site root domains a sign-in may return to (besides FRONTEND_URL).
    'oauth_callback_base_url' => env('OAUTH_CALLBACK_BASE_URL'),
    'public_site_root_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PUBLIC_SITE_ROOT_DOMAINS', ''))
    ))),

];
