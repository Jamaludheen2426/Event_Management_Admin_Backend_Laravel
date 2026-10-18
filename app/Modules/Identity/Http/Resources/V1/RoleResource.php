<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;

/**
 * v1 role: `name` is the label, `slug` the key, `company_id` the owner.
 * `permissions` only when loaded (detail, auth/me).
 *
 * @mixin Role
 *
 * @property Role $resource
 */
final class RoleResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $this->resource;

        return [
            'id' => $role->id,
            'name' => $role->label ?? $role->name,
            'slug' => $role->name,
            'description' => $role->getAttribute('description'),
            'level' => $role->level,
            'company_id' => $role->team_id,
            'is_default' => $role->is_default,
            'is_active' => $this->legacyActive($role->is_active, $role->approval_status),
            'approved_at' => $this->isoDate($role->getAttribute('approved_at')),
            'created_by' => $role->getAttribute('created_by'),
            'updated_by' => $role->getAttribute('updated_by'),
            'created_at' => $this->isoDate($role->created_at),
            'updated_at' => $this->isoDate($role->updated_at),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            ...$this->pendingApprovalField(),
        ];
    }
}
