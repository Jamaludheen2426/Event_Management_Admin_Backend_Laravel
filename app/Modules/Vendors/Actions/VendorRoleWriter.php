<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Core\Support\Slug;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * A vendor's own roles (guard vendor, spatie team = vendor). Level is always
 * 10 (custom); it is never editable (CLAUDE.md).
 *
 * Staff-portal rules kept from v1: staff cannot edit, delete or re-permission
 * their own role, and cannot grant permissions they do not hold.
 */
final class VendorRoleWriter
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @return Builder<Role> the current vendor's roles */
    public function roles(): Builder
    {
        return Role::query()->where('guard_name', 'vendor')->where('team_id', getPermissionsTeamId());
    }

    public function find(int $id): Role
    {
        return $this->roles()->find($id) ?? throw ApiException::notFound('Role not found');
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, VendorUser $actor): Role
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw ApiException::badRequest('Role name is required');
        }
        $slug = Slug::v1((string) (($input['slug'] ?? null) ?: $name));
        if ($this->roles()->where('name', $slug)->exists()) {
            throw ApiException::conflict('Role with this name already exists');
        }

        $role = Role::query()->create([
            'team_id' => $actor->vendor_id,
            'guard_name' => 'vendor',
            'name' => $slug,
            'label' => $name,
            'description' => $input['description'] ?? null,
            'level' => 10,
            'is_active' => true,
            'approval_status' => 'approved',
        ]);
        $this->activity->log('roles', 'create', "Created role: {$name}", $role, causer: $actor);

        return $role;
    }

    /** @param array<string, mixed> $input */
    public function update(Role $role, array $input, VendorUser $actor): Role
    {
        $this->assertNotOwnRole($role, $actor, 'You cannot edit your own role');

        $changes = [];
        if (isset($input['name']) && trim((string) $input['name']) !== '') {
            $changes['label'] = trim((string) $input['name']);
        }
        $slug = isset($input['slug']) && $input['slug'] !== '' ? Slug::v1((string) $input['slug'])
            : (isset($changes['label']) ? Slug::v1($changes['label']) : null);
        if ($slug !== null && $slug !== $role->name) {
            if ($this->roles()->where('name', $slug)->whereKeyNot($role->id)->exists()) {
                throw ApiException::conflict('Role with this name already exists');
            }
            $changes['name'] = $slug;
        }
        if (array_key_exists('description', $input)) {
            $changes['description'] = $input['description'];
        }
        if (array_key_exists('is_active', $input)) {
            $changes['is_active'] = Flag::bit($input['is_active'], 1) === 1;
        }
        $role->fill($changes)->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    public function delete(Role $role, VendorUser $actor): void
    {
        $this->assertNotOwnRole($role, $actor, 'You cannot delete your own role');
        $assigned = DB::table('model_has_roles')->where('role_id', $role->id)->count();
        if ($assigned > 0) {
            $who = $assigned > 1 ? "{$assigned} staff members are" : '1 staff member is';
            throw ApiException::badRequest("Cannot delete role: {$who} still assigned. Reassign them first.");
        }
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Replace the role's permissions: [{permission_id|id, requires_approval}] or ids.
     *
     * @param  mixed  $grants  raw body value
     */
    public function assignPermissions(Role $role, mixed $grants, VendorUser $actor): Role
    {
        $this->assertNotOwnRole($role, $actor, 'You cannot modify permissions of your own role');

        $sync = [];
        foreach (is_array($grants) ? $grants : [] as $grant) {
            $id = is_array($grant) ? Flag::id($grant['permission_id'] ?? $grant['id'] ?? null) : Flag::id($grant);
            if ($id === null) {
                throw ApiException::badRequest('Some permissions are invalid');
            }
            $sync[$id] = ['requires_approval' => is_array($grant) && filter_var($grant['requires_approval'] ?? false, FILTER_VALIDATE_BOOL)];
        }

        $valid = Permission::query()->where('guard_name', 'vendor')->whereKey(array_keys($sync))->pluck('id')->all();
        if (count($valid) !== count($sync)) {
            throw ApiException::badRequest('Some permissions are invalid');
        }
        if (! $actor->is_owner) {
            $own = $actor->loadMissing('roles')->role()?->permissions()->pluck('permissions.id')->all() ?? [];
            if (array_diff(array_keys($sync), $own) !== []) {
                throw ApiException::forbidden('You cannot assign permissions that you do not have yourself');
            }
        }

        $role->permissions()->sync($sync);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    private function assertNotOwnRole(Role $role, VendorUser $actor, string $message): void
    {
        if (! $actor->is_owner && $actor->loadMissing('roles')->role()?->id === $role->id) {
            throw ApiException::forbidden($message);
        }
    }
}
