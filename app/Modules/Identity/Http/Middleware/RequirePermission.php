<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Core\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1 hasPermission(...slugs): passes when the user holds ANY of the slugs.
 * Developer and super admin pass through Gate::before (IdentityServiceProvider).
 *
 * Usage: ->middleware('perm:employees.view') or 'perm:roles.manage,roles.edit'
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw ApiException::unauthorized('Authentication required');
        }

        if (! Gate::forUser($user)->any($permissions)) {
            throw ApiException::forbidden('Access denied. Insufficient permissions.');
        }

        return $next($request);
    }
}
