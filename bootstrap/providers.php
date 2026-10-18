<?php

declare(strict_types=1);
use App\Core\CoreServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Clients\ClientsServiceProvider;
use App\Modules\Events\EventsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Legacy\LegacyServiceProvider;
use App\Modules\Messaging\MessagingServiceProvider;
use App\Modules\Platform\PlatformServiceProvider;
use App\Modules\Sites\SitesServiceProvider;
use App\Modules\Templates\TemplatesServiceProvider;
use App\Modules\Vendors\VendorsServiceProvider;
use App\Providers\AppServiceProvider;

/*
| Core kernel first, then one provider per business module (in dependency order:
| a module may only depend on modules listed above it).
*/
return [
    AppServiceProvider::class,
    CoreServiceProvider::class,

    PlatformServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    TemplatesServiceProvider::class,
    VendorsServiceProvider::class,
    ClientsServiceProvider::class,
    BillingServiceProvider::class,
    EventsServiceProvider::class,
    MessagingServiceProvider::class,
    SitesServiceProvider::class,
    LegacyServiceProvider::class,
];
