<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Which event menus each plan grants is read on every client event open
 * (production pays ~374 ms per query), so readers cache it under a version
 * number. Any change to plans, plan menus or menu switches bumps the version,
 * and the next read sees the change at once (v1 invalidatePlanGrants()).
 */
final class PlanGrants
{
    private const VERSION_KEY = 'catalog:plan-grants:version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    /** A cache key that changes whenever grants change. */
    public static function key(string $suffix): string
    {
        return 'catalog:plan-grants:'.self::version().':'.$suffix;
    }
}
