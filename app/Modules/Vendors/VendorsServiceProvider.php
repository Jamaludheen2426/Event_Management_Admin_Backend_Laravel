<?php

declare(strict_types=1);

namespace App\Modules\Vendors;

use App\Core\Approvals\ApprovalRegistry;
use App\Core\Support\ModuleServiceProvider;
use App\Modules\Platform\Support\PluginUsage;
use App\Modules\Vendors\Actions\VendorWriter;
use App\Modules\Vendors\Http\Middleware\EnsureStaffUsable;
use App\Modules\Vendors\Http\Middleware\EnsureVendorOwner;
use App\Modules\Vendors\Http\Middleware\RequireStaffPermission;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Routing\Router;

/**
 * Vendors: vendor business accounts, vendor users (owner + staff) and their
 * sign-in, vendor roles and departments, the vendor's subscription tier.
 */
final class VendorsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('account.vendor', EnsureVendorOwner::class);
        $router->aliasMiddleware('account.staff', EnsureStaffUsable::class);
        $router->aliasMiddleware('staff.perm', RequireStaffPermission::class);

        $this->app->make(ApprovalRegistry::class)->register('vendors', 'vendor', VendorWriter::class);

        // The plugin card shows how many vendors have their website switched on.
        $this->app->make(PluginUsage::class)->register('website-management',
            static fn (int $companyId): int => Vendor::query()->where('company_id', $companyId)->where('website_enabled', true)->count());
    }
}
