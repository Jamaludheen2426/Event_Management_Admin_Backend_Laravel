<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Core\Exceptions\ApiException;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1 hasMinLevel(n). Developer and super admin always pass.
 *
 * Usage: ->middleware('level:100')
 */
final class RequireMinLevel
{
    public function handle(Request $request, Closure $next, string $level): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ApiException::unauthorized('Authentication required');
        }

        if (! $user->bypassesPermissions() && $user->roleLevel() < (int) $level) {
            throw ApiException::forbidden('Access denied. Insufficient role level.');
        }

        return $next($request);
    }
}
