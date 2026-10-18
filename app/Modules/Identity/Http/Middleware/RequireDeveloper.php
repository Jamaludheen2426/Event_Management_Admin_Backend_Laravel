<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Core\Exceptions\ApiException;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1 isDeveloper: only the developer role (companies, platform-wide tools).
 *
 * Usage: ->middleware('developer')
 */
final class RequireDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ApiException::unauthorized('Authentication required');
        }

        if ($user->role()?->name !== 'developer') {
            throw ApiException::forbidden('Access denied. Developer role required.');
        }

        return $next($request);
    }
}
