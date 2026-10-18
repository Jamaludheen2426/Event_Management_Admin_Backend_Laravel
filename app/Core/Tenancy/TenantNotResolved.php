<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use RuntimeException;

/**
 * Thrown when tenant-scoped data is touched before the tenant is known.
 *
 * Always a programming error (a route outside a portal group, a job that did
 * not set its tenant), so it surfaces as a 500, never as an empty result.
 */
final class TenantNotResolved extends RuntimeException
{
    public static function for(string $label): self
    {
        return new self("The current {$label} is not resolved. Route the request through a portal middleware group, or wrap the work in Tenancy::bypass().");
    }
}
