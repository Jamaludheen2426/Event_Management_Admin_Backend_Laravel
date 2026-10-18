<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Core\Exceptions\ApiException;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every signed-in admin request, after the token was accepted. An admin
 * who was deactivated, lost login access, whose role was switched off or whose
 * company was suspended is refused at once — v1 checked the same four things,
 * with the same words, in its auth middleware.
 *
 * Alias: account.admin (part of the portal.admin group).
 */
final class EnsureAdminAccountUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            self::assertUsable($user);
        }

        return $next($request);
    }

    /** Shared with login, which checks the same rules before issuing tokens. */
    public static function assertUsable(User $user, bool $checkRole = true): void
    {
        $user->loadMissing(['roles', 'company']);

        if (! $user->isUsable()) {
            throw ApiException::forbidden('Your account is not active. Please contact admin.');
        }

        if (! $user->login_access) {
            throw ApiException::forbidden('Your login access has been revoked. Please contact admin.');
        }

        $role = $user->role();
        if ($checkRole && ($role === null || ! $role->is_active)) {
            throw ApiException::forbidden('Your role is inactive. Please contact admin.');
        }

        if (! (bool) $user->company?->is_active) {
            throw ApiException::forbidden('Your company account is suspended. Please contact support.');
        }
    }
}
