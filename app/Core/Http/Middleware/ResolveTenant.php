<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\CurrentVendor;
use App\Core\Tenancy\ResolvesTenant;
use App\Core\Tenancy\TenantNotResolved;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs right after authentication in every portal group. It asks the signed-in
 * actor for its tenant and fills the request-scoped contexts, so from here on
 * every tenant-scoped query is filtered without any controller remembering to.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly CurrentCompany $company,
        private readonly CurrentVendor $vendor,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (! $actor instanceof ResolvesTenant) {
            throw TenantNotResolved::for('actor');
        }

        $this->company->set($actor->tenantCompanyId());
        $this->vendor->set($actor->tenantVendorId());
        $this->permissions->setPermissionsTeamId($actor->permissionTeamId());

        return $next($request);
    }
}
