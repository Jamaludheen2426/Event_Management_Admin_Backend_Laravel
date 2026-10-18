<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in admin (login, /auth/me, update-profile): the user, the role
 * WITH its permissions, the company, and `permissions` — the list of slugs the
 * admin frontend's PermissionGuard reads.
 *
 * v1 never filled `permissions`, so PermissionGuard hid every guarded screen
 * from employees whose role was not developer or super admin. Sending it is an
 * additive fix: no screen read anything else at that key.
 *
 * Load with User::query()->with(AuthUserResource::RELATIONS).
 *
 * @mixin User
 *
 * @property User $resource
 */
final class AuthUserResource extends V1Resource
{
    public const RELATIONS = ['roles.permissions.group', 'company', 'department'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $role = $user->role();
        $company = $user->company;

        return [
            ...(new UserResource($user))->toArray($request),
            'role' => $role === null ? null : new RoleResource($role),
            'company' => $company === null ? null : [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'is_active' => $this->flag($company->is_active),
                'logo' => $company->getAttribute('logo'),
            ],
            'permissions' => $role === null ? [] : $role->permissions->pluck('name')->values()->all(),
        ];
    }
}
