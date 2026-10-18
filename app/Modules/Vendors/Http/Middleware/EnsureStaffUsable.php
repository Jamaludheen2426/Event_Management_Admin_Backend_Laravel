<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Middleware;

use App\Core\Exceptions\ApiException;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Models\VendorUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff portal (alias account.staff): only staff logins that are active, have
 * login access, and whose vendor is active — v1's checks and words.
 */
final class EnsureStaffUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof VendorUser || $user->is_owner) {
            throw ApiException::unauthorized('Staff authentication required.');
        }

        self::assertUsable($user);

        return $next($request);
    }

    public static function assertUsable(VendorUser $staff, bool $atLogin = false): void
    {
        if (! $staff->is_active) {
            throw ApiException::forbidden('Your account is inactive. Please contact your vendor.');
        }
        if (! $staff->login_access) {
            throw ApiException::forbidden($atLogin
                ? 'Your login access has been revoked. Please contact your vendor.'
                : 'Your login access has been revoked.');
        }
        if ($staff->vendor === null || $staff->vendor->status !== VendorStatus::Active) {
            throw ApiException::forbidden('Your company account is suspended. Please contact support.');
        }
    }
}
