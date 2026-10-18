<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Event QR codes: AES-256-GCM, format EVQ1.<iv>.<tag>.<ciphertext>.
    | The key is base64 of the 32 bytes the Node backend derived with
    | scryptSync(EVENT_QR_SECRET, 'event-qr-v1', 32). Changing it makes every
    | printed invitation unreadable.
    */
    // Machine translation: comma-separated e-mails, each with its own daily quota.
    'mymemory' => [
        'emails' => env('MYMEMORY_EMAIL', ''),
        // Pause between requests when translating a whole site (MyMemory refuses bursts).
        'delay_ms' => (int) env('MYMEMORY_DELAY_MS', 350),
    ],

    'event_qr' => [
        'key' => env('EVENT_QR_KEY'),
    ],

    // Client social sign-in (laravel/socialite). The redirect URL is built from
    // clients.oauth_callback_base_url. v1 env names.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => null,
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect' => null,
    ],

    // Card payment providers (v1 env names). Neither is connected yet; while
    // both are empty clients can only record manual payment methods.
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
    ],

    'stripe' => [
        'key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret' => env('STRIPE_SECRET_KEY'),
    ],

    // One-time codes by WhatsApp and SMS (v1 env names).
    'msg91' => [
        'auth_key' => env('MSG91_WA_AUTHKEY', ''),
        'whatsapp_number' => env('MSG91_WA_NUMBER', ''),
        'whatsapp_namespace' => env('MSG91_WA_NAMESPACE', ''),
        'whatsapp_language' => env('MSG91_WA_LANG', 'en'),
        'otp_template' => env('MSG91_OTP_TEMPLATE', ''),
        'otp_button' => env('MSG91_OTP_BUTTON', 'true') !== 'false',
        'whatsapp_enabled' => env('MSG91_OTP_ENABLED', 'true') !== 'false',
        'sms_auth_key' => env('MSG91_SMS_AUTHKEY', ''),
        'sms_flow_id' => env('MSG91_SMS_FLOW_ID', ''),
        'sms_var' => env('MSG91_SMS_VAR', 'OTP'),
        'sms_app_hash_var' => env('MSG91_SMS_APP_HASH_VAR', ''),
        'sms_app_hash' => env('MSG91_SMS_APP_HASH', ''),
        'sms_enabled' => env('MSG91_SMS_OTP_ENABLED', 'false') === 'true',
    ],

    // The chat socket relay (chat-socket/): where it listens for Laravel's
    // pushes, and the secret both sides sign socket tokens and calls with.
    'chat_socket' => [
        'url' => env('CHAT_SOCKET_URL', ''),
        'secret' => env('CHAT_SOCKET_SECRET', ''),
    ],

    // The name authenticator apps show next to a client's 2FA entry.
    'two_factor' => [
        'issuer' => env('TWO_FACTOR_ISSUER', 'Event Invit'),
    ],

];
