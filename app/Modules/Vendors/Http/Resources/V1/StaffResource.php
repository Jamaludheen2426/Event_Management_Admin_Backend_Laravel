<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Http\Request;

/**
 * A staff member as v1 sent it (vendor_staff row without secrets), with
 * role_id, company_id, and — when loaded — role (with permissions) and
 * vendor {id, company_name, company_logo, status}.
 *
 * @property VendorUser $resource
 */
final class StaffResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $role = $s->relationLoaded('roles') ? $s->role() : null;
        $vendor = $s->relationLoaded('vendor') ? $s->vendor : null;

        $row = [
            'id' => $s->id,
            'vendor_id' => $s->vendor_id,
            'role_id' => $role?->id,
            'department_id' => $s->department_id,
            'emp_id' => $s->emp_id,
            'name' => $s->name,
            'email' => $s->email,
            'mobile' => $s->mobile,
            'designation' => $s->getAttribute('designation'),
            'doj' => $this->dateOnly($s->getAttribute('doj')),
            'dor' => $this->dateOnly($s->getAttribute('dor')),
            'dob' => $this->dateOnly($s->getAttribute('dob')),
            'profile_pic' => $s->profile_pic,
            'login_access' => $s->login_access,
            'is_active' => $this->flag($s->is_active),
            'work_status' => $s->work_status,
            'address' => $s->getAttribute('address'),
            'country' => $s->getAttribute('country'),
            'state' => $s->getAttribute('state'),
            'district' => $s->getAttribute('district'),
            'city' => $s->getAttribute('city'),
            'locality' => $s->getAttribute('locality'),
            'pincode' => $s->getAttribute('pincode'),
            'company_id' => $vendor?->company_id,
            'created_at' => $this->isoDate($s->getAttribute('created_at')),
            'updated_at' => $this->isoDate($s->getAttribute('updated_at')),
            'deleted_at' => $this->isoDate($s->getAttribute('deleted_at')),
        ];

        if ($s->relationLoaded('roles')) {
            $row['role'] = $role === null ? null : new VendorRoleResource($role);
        }
        if ($vendor !== null) {
            $row['vendor'] = [
                'id' => $vendor->id,
                'company_name' => $vendor->company_name,
                'company_logo' => $vendor->getAttribute('logo'),
                'status' => $vendor->status->value,
            ];
        }
        if ($s->relationLoaded('department')) {
            $row['department'] = $s->department?->only(['id', 'name']);
        }

        return $row;
    }
}
