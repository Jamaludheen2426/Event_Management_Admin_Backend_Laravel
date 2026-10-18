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
 * Vendor portal (alias account.vendor): only the vendor's owner login, and
 * only while the vendor is active. A staff token here is "not signed in" —
 * staff use the staff portal.
 */
final class EnsureVendorOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof VendorUser || ! $user->is_owner) {
            throw ApiException::unauthorized('Vendor authentication required.');
        }

        self::assertUsable($user);

        return $next($request);
    }

    public static function assertUsable(VendorUser $owner): void
    {
        if ($owner->vendor === null || $owner->vendor->status !== VendorStatus::Active) {
            throw ApiException::forbidden('Vendor account is inactive.');
        }
    }
}
