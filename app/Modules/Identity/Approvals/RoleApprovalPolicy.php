<?php

declare(strict_types=1);

namespace App\Modules\Identity\Approvals;

use App\Core\Approvals\ApprovalPolicy;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Admin approval rules, as v1 had them:
 *
 *  - role level >= 100 (super admin, developer) never needs approval and may review;
 *  - otherwise "<module>.<action>" needs approval when the user's role has that
 *    permission with requires_approval set ("update" falls back to "<module>.edit").
 *
 * Only admin users take part; any other actor never needs approval here.
 */
final class RoleApprovalPolicy implements ApprovalPolicy
{
    public function requiresApproval(Model $actor, string $module, string $action): bool
    {
        if (! $actor instanceof User || $actor->roleLevel() >= Role::LEVEL_SUPER_ADMIN || $actor->bypassesPermissions()) {
            return false;
        }

        $role = $actor->role();
        if ($role === null) {
            return false;
        }

        $slugs = ["{$module}.{$action}"];
        if ($action === 'update') {
            $slugs[] = "{$module}.edit";
        }

        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $role->id)
            ->whereIn('permissions.name', $slugs)
            ->where('permissions.guard_name', 'admin')
            ->where('role_has_permissions.requires_approval', true)
            ->exists();
    }

    public function canReview(Model $actor): bool
    {
        return $actor instanceof User
            && ($actor->bypassesPermissions() || $actor->roleLevel() >= Role::LEVEL_SUPER_ADMIN);
    }
}
