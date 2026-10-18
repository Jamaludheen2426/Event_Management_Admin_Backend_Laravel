<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Middleware;

use App\Core\Exceptions\ApiException;
use App\Modules\Vendors\Models\VendorUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1 hasStaffPermission(...slugs): the staff member's role must hold any of
 * the slugs. Usage: ->middleware('staff.perm:client.view')
 */
final class RequireStaffPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $staff = $request->user();
        if (! $staff instanceof VendorUser) {
            throw ApiException::unauthorized('Staff authentication required.');
        }

        $role = $staff->loadMissing('roles')->role();
        if ($role === null) {
            throw ApiException::forbidden('No role assigned. Please contact your vendor.');
        }

        $held = $role->permissions()->pluck('name')->all();
        if (array_intersect($permissions, $held) === []) {
            throw ApiException::forbidden('Access denied. Insufficient permissions.');
        }

        return $next($request);
    }
}
