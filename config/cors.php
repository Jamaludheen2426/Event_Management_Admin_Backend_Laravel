<?php

declare(strict_types=1);

/*
| Read by App\Core\Http\Middleware\Cors, which replaces Laravel's HandleCors.
|
| Two policies, the same ones the Node API used:
|  - /api/v1/public/* is the tenant public-site read model. Tenant domains are
|    open-ended, so it answers any origin WITHOUT credentials.
|  - Everything else carries login cookies, so only the listed frontend origins
|    may call it, WITH credentials.
| A few public paths set the client-portal session cookie and therefore must use
| the credentialed list (a wildcard response makes the browser drop Set-Cookie).
*/

return [

    'frontend_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000,http://localhost:3001'))
    ))),

    'public_prefix' => 'api/v1/public/',

    'cookie_bearing_public_paths' => [
        'api/v1/public/website-clients/login',
        'api/v1/public/website-clients/login/2fa/verify',
        'api/v1/public/website-clients/logout',
    ],

];
