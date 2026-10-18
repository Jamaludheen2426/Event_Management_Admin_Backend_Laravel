<?php

declare(strict_types=1);

/*
| Architecture rules from docs/LARAVEL_MIGRATION_PLAN.md (A1, A11).
| These fail the build when a boundary is crossed, so the structure cannot
| quietly erode one shortcut at a time.
*/

arch('every application file declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('the shared kernel never depends on a business module')
    ->expect('App\Core')
    ->not->toUse('App\Modules');

arch('modules depend one way: Platform is the base')
    ->expect('App\Modules\Platform')
    ->not->toUse(['App\Modules\Identity', 'App\Modules\Vendors']);

arch('Identity does not depend on Vendors')
    ->expect('App\Modules\Identity')
    ->not->toUse('App\Modules\Vendors');

arch('controllers never touch the database directly (Actions and Queries do)')
    ->expect([
        'App\Modules\Identity\Http\Controllers', 'App\Modules\Platform\Http\Controllers',
        'App\Modules\Catalog\Http\Controllers', 'App\Modules\Templates\Http\Controllers', 'App\Core\Http\Controllers',
        'App\Modules\Vendors\Http\Controllers', 'App\Modules\Clients\Http\Controllers',
        'App\Modules\Billing\Http\Controllers', 'App\Modules\Events\Http\Controllers',
        'App\Modules\Messaging\Http\Controllers', 'App\Modules\Sites\Http\Controllers',
    ])
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Query\Builder']);

arch('controllers are final and end in Controller')
    ->expect([
        'App\Modules\Identity\Http\Controllers', 'App\Modules\Platform\Http\Controllers',
        'App\Modules\Catalog\Http\Controllers', 'App\Modules\Templates\Http\Controllers',
        'App\Modules\Vendors\Http\Controllers', 'App\Modules\Clients\Http\Controllers',
        'App\Modules\Billing\Http\Controllers', 'App\Modules\Events\Http\Controllers',
        'App\Modules\Messaging\Http\Controllers', 'App\Modules\Sites\Http\Controllers',
    ])
    ->toBeFinal()
    ->toHaveSuffix('Controller');

arch('Catalog sits below Templates and Vendors')
    ->expect('App\Modules\Catalog')
    ->not->toUse(['App\Modules\Templates', 'App\Modules\Vendors']);

arch('Templates does not depend on Vendors')
    ->expect('App\Modules\Templates')
    ->not->toUse('App\Modules\Vendors');

arch('Catalog and Templates models extend the shared base model')
    ->expect(['App\Modules\Catalog\Models', 'App\Modules\Templates\Models'])
    ->toExtend('App\Core\Database\Model');

arch('Vendors does not depend on Clients')
    ->expect('App\Modules\Vendors')
    ->not->toUse('App\Modules\Clients');

arch('lower modules do not depend on Clients')
    ->expect(['App\Modules\Platform', 'App\Modules\Identity', 'App\Modules\Catalog', 'App\Modules\Templates'])
    ->not->toUse('App\Modules\Clients');

arch('vendor-layer aggregate roots are tenant scoped')
    ->expect(['App\Modules\Clients\Models\Client', 'App\Modules\Clients\Models\NewsletterSubscriber', 'App\Modules\Vendors\Models\VendorUser'])
    ->toUseTrait('App\Core\Tenancy\BelongsToVendor');

arch('modules below Billing do not depend on it')
    ->expect(['App\Modules\Platform', 'App\Modules\Identity', 'App\Modules\Catalog', 'App\Modules\Templates', 'App\Modules\Vendors', 'App\Modules\Clients'])
    ->not->toUse('App\Modules\Billing');

arch('modules below Events do not depend on it')
    ->expect(['App\Modules\Platform', 'App\Modules\Identity', 'App\Modules\Catalog', 'App\Modules\Templates', 'App\Modules\Vendors', 'App\Modules\Clients', 'App\Modules\Billing'])
    ->not->toUse('App\Modules\Events');

arch('events are tenant scoped')
    ->expect('App\Modules\Events\Models\Event')
    ->toUseTrait('App\Core\Tenancy\BelongsToVendor');

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'print_r'])
    ->not->toBeUsed();

arch('models extend the shared base model or Laravel\'s authenticatable user')
    ->expect('App\Modules\Platform\Models')
    ->toExtend('App\Core\Database\Model');

arch()->preset()->security()->ignoring('App\Core\Support\EventQr');

arch('modules below Messaging do not depend on it')
    ->expect(['App\Modules\Platform', 'App\Modules\Identity', 'App\Modules\Catalog', 'App\Modules\Templates', 'App\Modules\Vendors', 'App\Modules\Clients', 'App\Modules\Billing', 'App\Modules\Events'])
    ->not->toUse('App\Modules\Messaging');

arch('Messaging models extend the shared base model')
    ->expect('App\Modules\Messaging\Models')
    ->toExtend('App\Core\Database\Model');

arch('modules below Sites do not depend on it')
    ->expect(['App\Modules\Platform', 'App\Modules\Identity', 'App\Modules\Catalog', 'App\Modules\Templates', 'App\Modules\Vendors', 'App\Modules\Clients', 'App\Modules\Billing', 'App\Modules\Events', 'App\Modules\Messaging'])
    ->not->toUse('App\Modules\Sites');

arch('Sites models extend the shared base model')
    ->expect('App\Modules\Sites\Models')
    ->toExtend('App\Core\Database\Model');

arch('sites are tenant scoped')
    ->expect('App\Modules\Sites\Models\Site')
    ->toUseTrait('App\Core\Tenancy\BelongsToVendor');

arch('nothing depends on the one-time legacy import')
    ->expect(['App\Modules\Platform', 'App\Modules\Identity', 'App\Modules\Catalog', 'App\Modules\Templates', 'App\Modules\Vendors', 'App\Modules\Clients', 'App\Modules\Billing', 'App\Modules\Events', 'App\Modules\Messaging', 'App\Modules\Sites', 'App\Core'])
    ->not->toUse('App\Modules\Legacy');
