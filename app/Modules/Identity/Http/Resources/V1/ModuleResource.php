<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\PermissionGroup;
use Illuminate\Http\Request;

/**
 * v1 "module" = a permission group, with its permissions.
 *
 * @mixin PermissionGroup
 *
 * @property PermissionGroup $resource
 */
final class ModuleResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $group = $this->resource;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'slug' => $group->slug,
            'description' => $group->getAttribute('description'),
            'company_id' => null,
            'is_active' => $this->flag($group->is_active),
            'created_at' => $this->isoDate($group->created_at),
            'updated_at' => $this->isoDate($group->updated_at),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
