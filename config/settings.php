<?php

declare(strict_types=1);

/*
| Settings whose values are secrets. They are encrypted at rest and never
| returned by the public settings endpoint.
*/
return [

    'secret_keys' => [
        'aws_access_key', 'aws_secret_key',
        'google_client_secret', 'facebook_app_secret',
        'stripe_secret_key', 'stripe_webhook_secret',
        'paypal_secret', 'razorpay_key_secret',
        'paystack_secret_key', 'mollie_api_key',
        'flutterwave_secret_key', 'flutterwave_encryption_key',
        'recaptcha_secret_key', 'twilio_auth_token', 'google_maps_api_key',
    ],

    // Hidden from GET /settings/public in addition to every secret key (v1 list).
    'private_keys' => [
        'google_redirect_uri', 'facebook_redirect_uri',
    ],

    // Groups readable without login (v1 publicGroups).
    'public_groups' => ['general', 'theme', 'appearance', 'analytics', 'social_login'],

];
