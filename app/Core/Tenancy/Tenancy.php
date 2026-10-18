<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Closure;

/**
 * Entry point for code that must look across tenants on purpose: the legacy
 * import, platform-wide admin reports, seeders. Everything else runs scoped.
 *
 *   Tenancy::bypass(fn () => Client::query()->count());
 */
final class Tenancy
{
    private int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback): mixed
    {
        $tenancy = app(self::class);
        $tenancy->depth++;

        try {
            return $callback();
        } finally {
            $tenancy->depth--;
        }
    }

    public static function isBypassed(): bool
    {
        return app(self::class)->depth > 0;
    }

    public static function vendorId(): int
    {
        return app(CurrentVendor::class)->id();
    }

    public static function companyId(): int
    {
        return app(CurrentCompany::class)->id();
    }
}
