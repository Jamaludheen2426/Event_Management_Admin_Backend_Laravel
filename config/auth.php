<?php

declare(strict_types=1);

use App\Modules\Clients\Models\Client;
use App\Modules\Identity\Models\User;
use App\Modules\Vendors\Models\VendorUser;

/*
| Portal guards share one token store (Sanctum personal_access_tokens).
|
| The sanctum driver checks that a token's owner is an instance of the guard's
| provider model, so a client token can never open an admin route even though
| all tokens live in one table.
|
| admin  -> Identity User        (admin panel)
| vendor -> Vendors VendorUser   (vendor portal = owners, staff portal = staff)
| client -> Clients Client       (client portal, Flutter app, vendor-client portal)
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'admin' => ['driver' => 'sanctum', 'provider' => 'users'],
        'vendor' => ['driver' => 'sanctum', 'provider' => 'vendor_users'],
        'client' => ['driver' => 'sanctum', 'provider' => 'clients'],
    ],

    'providers' => [
        'users' => ['driver' => 'eloquent', 'model' => User::class],
        'vendor_users' => ['driver' => 'eloquent', 'model' => VendorUser::class],
        'clients' => ['driver' => 'eloquent', 'model' => Client::class],
    ],

    /*
    | What a request without a valid login hears, by path prefix (first match).
    | v1 answered each portal in its own words.
    */
    'unauthenticated_messages' => [
        'api/v1/vendors/staff' => 'Staff authentication required.',
        'api/v1/vendors/client' => 'Client authentication required.',
        'api/v1/vendors' => 'Vendor authentication required.',
        'api/v1/client' => 'Client authentication required.',
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 10,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    // One developer login created by `db:seed` on local installs only (never in production).
    'seed_admin' => [
        'email' => env('SEED_ADMIN_EMAIL'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],

    // Admin password reset codes (v1: 6 digits, 10 minutes).
    'reset_code_minutes' => 10,

];
