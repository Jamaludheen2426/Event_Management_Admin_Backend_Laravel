<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\Permission;
use Illuminate\Http\Request;

/**
 * v1 permission: `name` is the label, `slug` the key, `module` the group slug.
 * Inside a role it also carries RolePermission.requires_approval (the role
 * screen reads that path).
 *
 * @mixin Permission
 *
 * @property Permission $resource
 */
final class PermissionResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $permission = $this->resource;
        $data = [
            'id' => $permission->id,
            'name' => $permission->label ?? $permission->name,
            'slug' => $permission->name,
            'module' => $permission->relationLoaded('group') ? ($permission->group->slug ?? $permission->moduleSlug()) : $permission->moduleSlug(),
            'module_id' => $permission->permission_group_id,
            'description' => $permission->getAttribute('description'),
            'company_id' => null,
            'is_active' => $this->flag($permission->is_active),
            'created_at' => $this->isoDate($permission->created_at),
            'updated_at' => $this->isoDate($permission->updated_at),
        ];

        $pivot = $permission->getRelationValue('pivot');
        if ($pivot !== null) {
            $data['RolePermission'] = ['requires_approval' => (bool) $pivot->getAttribute('requires_approval')];
        }

        return $data;
    }
}
