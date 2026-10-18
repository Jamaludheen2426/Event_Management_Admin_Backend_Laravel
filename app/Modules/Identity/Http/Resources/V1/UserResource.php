<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * An admin user in v1 keys.
 *
 * Location keys keep v1's names: v1 `city_id` held a DISTRICT id and
 * `pincode_id` a city (locality) id. The schema now names them honestly
 * (district_id, city_id); this resource and UserData translate.
 *
 * Relations appear when loaded: role, department_ref, country, state, city
 * (= the district, as v1 sent it).
 *
 * @mixin User
 *
 * @property User $resource
 */
final class UserResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $role = $user->relationLoaded('roles') ? $user->role() : null;
        $department = $user->relationLoaded('department') ? $user->department : null;

        return [
            'id' => $user->id,
            'company_id' => $user->company_id,
            'full_name' => $user->full_name,
            'username' => $user->getAttribute('username'),
            'email' => $user->email,
            'phone' => $user->getAttribute('phone'),
            'avatar' => $user->getAttribute('avatar'),
            'dob' => $this->dateOnly($user->getAttribute('dob')),
            'gender' => $user->getAttribute('gender'),
            'marital_status' => $user->getAttribute('marital_status'),
            'is_active' => $this->legacyActive($user->is_active, $user->approval_status),
            'country_id' => $user->getAttribute('country_id'),
            'state_id' => $user->getAttribute('state_id'),
            'city_id' => $user->getAttribute('district_id'),
            'pincode_id' => $user->getAttribute('city_id'),
            'address' => $user->getAttribute('address'),
            'department_id' => $user->department_id,
            'department' => $department?->name,
            'designation' => $user->getAttribute('designation'),
            'doj' => $this->dateOnly($user->getAttribute('doj')),
            'dor' => $this->dateOnly($user->getAttribute('dor')),
            'timezone' => $user->getAttribute('timezone'),
            'login_access' => $this->flag($user->login_access),
            'email_verified_at' => $this->isoDate($user->getAttribute('email_verified_at')),
            'last_login_at' => $this->isoDate($user->last_login_at),
            'google_id' => null,
            'facebook_id' => null,
            'auth_provider' => 'local',
            'social_avatar' => null,
            'role_id' => $role?->id,
            'created_by' => $user->getAttribute('created_by'),
            'updated_by' => $user->getAttribute('updated_by'),
            'created_at' => $this->isoDate($user->created_at),
            'updated_at' => $this->isoDate($user->updated_at),
            'deleted_at' => $this->isoDate($user->getAttribute('deleted_at')),
            'role' => $this->when($role !== null, fn (): RoleResource => new RoleResource($role)),
            'department_ref' => $this->when($department !== null, fn (): array => ['id' => $department?->id, 'name' => $department?->name]),
            'country' => $this->whenLoaded('country', fn (): ?array => $user->country === null ? null : [
                'id' => $user->country->id, 'name' => $user->country->name, 'code' => $user->country->code,
            ]),
            'state' => $this->whenLoaded('state', fn (): ?array => $user->state === null ? null : [
                'id' => $user->state->id, 'name' => $user->state->name,
            ]),
            'city' => $this->whenLoaded('district', fn (): ?array => $user->district === null ? null : [
                'id' => $user->district->id, 'name' => $user->district->name,
            ]),
            'cityRef' => $this->whenLoaded('city', fn (): ?array => $user->city === null ? null : [
                'name' => $user->city->name, 'pincode' => $user->city->pincode,
            ]),
            ...$this->pendingApprovalField(),
        ];
    }
}
