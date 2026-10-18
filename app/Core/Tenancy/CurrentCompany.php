<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/** The company (platform owner) the current request belongs to. */
final class CurrentCompany extends TenantContext
{
    protected static function label(): string
    {
        return 'company';
    }
}
