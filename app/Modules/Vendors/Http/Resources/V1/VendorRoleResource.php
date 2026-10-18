<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Http\Resources\V1\RoleResource;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;

/**
 * A vendor role: the admin role shape, owned by a vendor (vendor_id) rather
 * than a company.
 *
 * @property Role $resource
 */
final class VendorRoleResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...(new RoleResource($this->resource))->toArray($request),
            'company_id' => null,
            'vendor_id' => $this->resource->team_id,
        ];
    }
}
