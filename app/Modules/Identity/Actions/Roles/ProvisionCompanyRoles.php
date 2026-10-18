<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Roles;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The three built-in admin roles every company has. Idempotent.
 *
 *   developer    1000  platform staff; passes every check
 *   super_admin   100  company owner; passes every check, reviews approvals
 *   admin          50  default role for new employees; every permission granted
 *
 * v1 copied all modules and permissions into each new company. Permissions are
 * now one global catalogue; only roles belong to a company.
 */
final class ProvisionCompanyRoles
{
    private const ROLES = [
        'developer' => ['Developer', Role::LEVEL_DEVELOPER, false, 'Platform developer with full access'],
        'super_admin' => ['Super Admin', Role::LEVEL_SUPER_ADMIN, false, 'Company owner with full access'],
        'admin' => ['Admin', 50, true, 'Administrator'],
    ];

    /** @return array<string, Role> slug => role */
    public function handle(int $companyId): array
    {
        $roles = [];
        foreach (self::ROLES as $slug => [$label, $level, $isDefault, $description]) {
            $roles[$slug] = Role::query()->firstOrCreate(
                ['team_id' => $companyId, 'name' => $slug, 'guard_name' => 'admin'],
                [
                    'label' => $label,
                    'level' => $level,
                    'is_default' => $isDefault,
                    'is_active' => true,
                    'approval_status' => 'approved',
                    'description' => $description,
                ],
            );
        }

        if ($roles['admin']->wasRecentlyCreated) {
            $roles['admin']->permissions()->sync(
                Permission::query()->where('guard_name', 'admin')->pluck('id')->all(),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $roles;
    }
}
