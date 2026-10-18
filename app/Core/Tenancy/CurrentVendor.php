<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/** The vendor the current request acts for. Admin users act for the house vendor. */
final class CurrentVendor extends TenantContext
{
    protected static function label(): string
    {
        return 'vendor';
    }
}
