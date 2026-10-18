<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * Implemented by every authenticatable model (admin User, VendorUser, Client).
 *
 * ResolveTenant asks the signed-in actor for its tenant instead of knowing each
 * model class, so Core never depends on a business module.
 */
interface ResolvesTenant
{
    public function tenantCompanyId(): int;

    /** Admin users answer the house vendor; vendor users and clients answer their own vendor. */
    public function tenantVendorId(): int;

    /** spatie/laravel-permission team: company id for admin roles, vendor id for vendor roles. */
    public function permissionTeamId(): ?int;
}
