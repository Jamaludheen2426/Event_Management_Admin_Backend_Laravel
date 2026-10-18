<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\Department;
use Illuminate\Http\Request;

/**
 * @mixin Department
 *
 * @property Department $resource
 */
final class DepartmentResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $department = $this->resource;

        return [
            'id' => $department->id,
            'company_id' => $department->company_id,
            'name' => $department->name,
            'description' => $department->getAttribute('description'),
            'is_active' => $this->flag($department->is_active),
            'created_at' => $this->isoDate($department->created_at),
            'updated_at' => $this->isoDate($department->updated_at),
        ];
    }
}
