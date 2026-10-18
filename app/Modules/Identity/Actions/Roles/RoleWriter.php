<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Roles;

use App\Core\Activity\ActivityLogger;
use App\Core\Approvals\ApprovalHandler;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Slug;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin roles of the current company (v1 /roles). Also the approval handler
 * for module "roles".
 *
 * Rules added over v1 (which had none of them):
 *  - a role cannot be given a level above the editor's own;
 *  - the built-in developer and super admin roles cannot be edited away or deleted;
 *  - an editor can only grant permissions they hold themselves.
 */
final class RoleWriter implements ApprovalHandler
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param array{name: string, slug?: string|null, description?: string|null, level?: int|null} $input */
    public function create(array $input, User $actor, bool $pending = false): Role
    {
        $slug = Slug::v1((string) (($input['slug'] ?? null) ?: $input['name']));
        $this->assertSlugFree($slug, $actor->company_id);
        $level = $this->allowedLevel($input['level'] ?? null, $actor);

        $role = Role::query()->create([
            'team_id' => $actor->company_id,
            'guard_name' => 'admin',
            'name' => $slug,
            'label' => $input['name'],
            'description' => $input['description'] ?? null,
            'level' => $level,
            'is_default' => false,
            'is_active' => ! $pending,
            'approval_status' => $pending ? 'pending' : 'approved',
            'approved_at' => $pending ? null : now(),
            'created_by' => $actor->id,
        ]);

        $this->activity->log('roles', 'create', "Created role: {$role->label}", $role, [], $input);

        return $role;
    }

    /** @param array<string, mixed> $input only the sent keys */
    public function update(Role $role, array $input, User $actor): Role
    {
        $this->assertEditable($role, $actor);
        $old = $role->only(['name', 'label', 'description', 'level', 'is_active']);

        $changes = ['updated_by' => $actor->id];
        if (isset($input['name'])) {
            $changes['label'] = $input['name'];
        }
        if (isset($input['slug']) && $input['slug'] !== $role->name && ! $role->bypassesPermissions()) {
            $slug = Slug::v1((string) $input['slug']);
            $this->assertSlugFree($slug, (int) $role->team_id, $role->id);
            $changes['name'] = $slug;
        }
        if (array_key_exists('description', $input)) {
            $changes['description'] = $input['description'];
        }
        if (isset($input['level']) && ! $role->bypassesPermissions()) {
            $changes['level'] = $this->allowedLevel($input['level'], $actor);
        }
        if (array_key_exists('is_active', $input) && in_array((int) $input['is_active'], [0, 1], true)) {
            if ($role->bypassesPermissions() && (int) $input['is_active'] === 0) {
                throw ApiException::badRequest('Cannot deactivate a system role');
            }
            $changes['is_active'] = (int) $input['is_active'] === 1;
        }

        $role->fill($changes)->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('roles', 'update', "Updated role: {$role->label}", $role, $old, $input);

        return $role;
    }

    public function delete(Role $role, User $actor): void
    {
        if ($role->is_default) {
            throw ApiException::badRequest('Cannot delete default role');
        }
        if ($role->bypassesPermissions()) {
            throw ApiException::badRequest('Cannot delete a system role');
        }
        $this->assertEditable($role, $actor);

        $assigned = DB::table('model_has_roles')->where('role_id', $role->id)->count();
        if ($assigned > 0) {
            $who = $assigned > 1 ? "{$assigned} employees are" : '1 employee is';
            throw ApiException::badRequest("Cannot delete role: {$who} still assigned to this role. Reassign or delete them first.");
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('roles', 'delete', "Deleted role: {$role->label}", $role);
    }

    /**
     * Replace the role's permissions. Accepts v1's two body shapes:
     *   { permissions: [{ permission_id, requires_approval }] }   current
     *   { permission_ids: [1, 2, 3] }                              old
     *
     * @param  list<array{permission_id: int, requires_approval?: bool}>  $grants
     */
    public function assignPermissions(Role $role, array $grants, User $actor): Role
    {
        $this->assertEditable($role, $actor);

        $ids = array_values(array_unique(array_map(static fn (array $g): int => (int) $g['permission_id'], $grants)));
        $found = Permission::query()->where('guard_name', 'admin')->whereKey($ids)->pluck('name', 'id');
        if ($found->count() !== count($ids)) {
            throw ApiException::badRequest('Some permissions are invalid or belong to another company');
        }

        if (! $actor->bypassesPermissions()) {
            $own = $actor->role()?->permissions()->pluck('name')->all() ?? [];
            $foreign = array_diff($found->values()->all(), $own);
            if ($foreign !== []) {
                throw ApiException::forbidden('You cannot grant permissions you do not have: '.implode(', ', $foreign));
            }
        }

        $sync = [];
        foreach ($grants as $grant) {
            $sync[(int) $grant['permission_id']] = ['requires_approval' => (bool) ($grant['requires_approval'] ?? false)];
        }

        DB::transaction(static fn () => $role->permissions()->sync($sync));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('roles', 'assign_permissions', "Assigned permissions to role: {$role->label}", $role, [], ['permission_ids' => $ids]);

        return $role;
    }

    // ---- ApprovalHandler ------------------------------------------------

    public function snapshot(int $id): ?array
    {
        return $this->companyRoles()->find($id)?->attributesToArray();
    }

    public function createPending(array $payload, Model $requester): int
    {
        /** @var User $requester */
        /** @var array{name: string, slug?: string|null, description?: string|null, level?: int|null} $payload */
        return $this->create($payload, $requester, pending: true)->id;
    }

    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model
    {
        /** @var User $requester */
        $role = $resourceId === null ? null : $this->companyRoles()->find($resourceId);
        if ($role === null) {
            throw ApiException::notFound('Role not found');
        }

        if ($action === 'create') {
            $role->forceFill(['approval_status' => 'approved', 'is_active' => true, 'approved_at' => now()])->save();

            return $role;
        }

        if ($action === 'delete') {
            $this->delete($role, $requester);

            return null;
        }

        return $this->update($role, $payload, $requester);
    }

    public function discard(int $resourceId, Model $reviewer): void
    {
        $this->companyRoles()->whereKey($resourceId)->where('approval_status', 'pending')->first()?->delete();
    }

    /** @return Builder<Role> */
    public function companyRoles(): Builder
    {
        return Role::query()->where('guard_name', 'admin')->where('team_id', getPermissionsTeamId());
    }

    // ---- rules ------------------------------------------------------------

    private function assertSlugFree(string $slug, int $companyId, ?int $exceptId = null): void
    {
        if ($slug === '') {
            throw ApiException::badRequest('Role name is required');
        }

        $taken = Role::query()->where('guard_name', 'admin')->where('team_id', $companyId)->where('name', $slug)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ApiException::conflict('Role with this slug already exists');
        }
    }

    private function allowedLevel(mixed $level, User $actor): int
    {
        $level = $level === null ? 10 : (int) $level;
        if ($level < 1) {
            throw ApiException::badRequest('Level must be at least 1');
        }
        if (! $actor->bypassesPermissions() && $level > $actor->roleLevel()) {
            throw ApiException::forbidden('Cannot set a role level higher than your own');
        }
        if ($level >= Role::LEVEL_DEVELOPER && $actor->role()?->name !== 'developer') {
            throw ApiException::forbidden('Cannot set a role level higher than your own');
        }

        return $level;
    }

    private function assertEditable(Role $role, User $actor): void
    {
        if ($actor->bypassesPermissions()) {
            if ($role->name === 'developer' && $actor->role()?->name !== 'developer') {
                throw ApiException::forbidden('Only a developer can change the developer role');
            }

            return;
        }

        if ($role->level >= $actor->roleLevel()) {
            throw ApiException::forbidden('Cannot modify a role with an equal or higher level than your own');
        }
    }
}
