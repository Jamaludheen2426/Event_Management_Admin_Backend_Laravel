<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Permissions;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Slug;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\PermissionGroup;
use App\Modules\Identity\Support\AdminPermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * v1 /modules and /permissions: the admin permission catalogue.
 *
 * The catalogue is global now (v1 copied it per company). Groups and
 * permissions the API itself checks (AdminPermissionCatalogue) cannot be
 * deleted — deleting one would lock every non-super-admin out of a screen.
 */
final class PermissionCatalogueWriter
{
    /** v1 STANDARD_ACTIONS, generated for every new module. */
    private const STANDARD_ACTIONS = [
        'view' => ['View', 'Can view and list records'],
        'create' => ['Create', 'Can create new records'],
        'edit' => ['Edit', 'Can edit and update records'],
        'delete' => ['Delete', 'Can delete records'],
        'manage' => ['Manage', 'Full control including special operations'],
    ];

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @param array{name: string, slug?: string|null, description?: string|null} $input */
    public function createGroup(array $input): PermissionGroup
    {
        $slug = Slug::v1((string) (($input['slug'] ?? null) ?: $input['name']));
        $this->assertGroupSlugFree($slug);

        $group = DB::transaction(function () use ($input, $slug): PermissionGroup {
            $group = PermissionGroup::query()->create([
                'name' => $input['name'],
                'slug' => $slug,
                'description' => $input['description'] ?? null,
            ]);

            foreach (self::STANDARD_ACTIONS as $action => [$label, $description]) {
                Permission::query()->firstOrCreate(
                    ['name' => "{$slug}.{$action}", 'guard_name' => 'admin'],
                    ['label' => "{$input['name']} {$label}", 'permission_group_id' => $group->id, 'description' => "{$description} for {$input['name']}"],
                );
            }

            return $group;
        });

        $this->forgetCache();
        $this->activity->log('modules', 'create', "Created module: {$group->name} with ".count(self::STANDARD_ACTIONS).' permissions', $group);

        return $group;
    }

    /** @param array<string, mixed> $input */
    public function updateGroup(PermissionGroup $group, array $input): PermissionGroup
    {
        if (isset($input['slug']) && $input['slug'] !== $group->slug) {
            if ($this->isSystemGroup($group)) {
                throw ApiException::badRequest('Cannot change the slug of a system module');
            }
            $slug = Slug::v1((string) $input['slug']);
            $this->assertGroupSlugFree($slug, $group->id);
            $group->slug = $slug;
        }
        if (isset($input['name'])) {
            $group->name = (string) $input['name'];
        }
        if (array_key_exists('description', $input)) {
            $group->setAttribute('description', $input['description']);
        }
        if (array_key_exists('is_active', $input)) {
            $group->is_active = (int) $input['is_active'] === 1;
        }
        $group->save();

        $this->activity->log('modules', 'update', "Updated module: {$group->name}", $group, [], $input);

        return $group;
    }

    public function deleteGroup(PermissionGroup $group): void
    {
        if ($this->isSystemGroup($group)) {
            throw ApiException::badRequest('Cannot delete a system module');
        }
        if (Permission::query()->where('permission_group_id', $group->id)->where('guard_name', '!=', 'admin')->exists()) {
            throw ApiException::badRequest('This module is also used by the vendor portal and cannot be deleted here');
        }

        DB::transaction(static function () use ($group): void {
            Permission::query()->where('permission_group_id', $group->id)->delete();
            $group->delete();
        });

        $this->forgetCache();
        $this->activity->log('modules', 'delete', "Deleted module: {$group->name}", $group);
    }

    /** v1 POST /modules/:id/permissions { name, action, description } */
    public function addToGroup(PermissionGroup $group, string $name, string $action, ?string $description): Permission
    {
        $slug = $group->slug.'.'.Slug::v1($action);
        if (Permission::query()->where('guard_name', 'admin')->where('name', $slug)->exists()) {
            throw ApiException::conflict("Permission \"{$slug}\" already exists");
        }

        $permission = Permission::query()->create([
            'name' => $slug,
            'guard_name' => 'admin',
            'label' => "{$group->name} {$name}",
            'permission_group_id' => $group->id,
            'description' => $description,
        ]);

        $this->forgetCache();
        $this->activity->log('modules', 'add_permission', "Added custom permission \"{$slug}\" to module: {$group->name}", $group);

        return $permission;
    }

    /** @param array{name: string, slug?: string|null, module: string, description?: string|null} $input */
    public function createPermission(array $input): Permission
    {
        $module = Slug::v1($input['module']);
        $slug = ($input['slug'] ?? null) ?: $module.'.'.Slug::v1($input['name']);
        if (Permission::query()->where('guard_name', 'admin')->where('name', $slug)->exists()) {
            throw ApiException::conflict('Permission with this slug already exists');
        }

        $group = PermissionGroup::query()->firstOrCreate(['slug' => $module], ['name' => str($module)->headline()->toString()]);

        $permission = Permission::query()->create([
            'name' => $slug,
            'guard_name' => 'admin',
            'label' => $input['name'],
            'permission_group_id' => $group->id,
            'description' => $input['description'] ?? null,
        ]);

        $this->forgetCache();
        $this->activity->log('permissions', 'create', "Created permission: {$slug}", $permission);

        return $permission;
    }

    /** @param array<string, mixed> $input */
    public function updatePermission(Permission $permission, array $input): Permission
    {
        if (isset($input['slug']) && $input['slug'] !== $permission->name) {
            if ($this->isSystemPermission($permission)) {
                throw ApiException::badRequest('Cannot change the slug of a system permission');
            }
            if (Permission::query()->where('guard_name', 'admin')->where('name', $input['slug'])->whereKeyNot($permission->id)->exists()) {
                throw ApiException::conflict('Permission with this slug already exists');
            }
            $permission->name = (string) $input['slug'];
        }
        if (isset($input['name'])) {
            $permission->label = (string) $input['name'];
        }
        if (isset($input['module'])) {
            $module = Slug::v1((string) $input['module']);
            $permission->permission_group_id = PermissionGroup::query()
                ->firstOrCreate(['slug' => $module], ['name' => str($module)->headline()->toString()])->id;
        }
        if (array_key_exists('description', $input)) {
            $permission->setAttribute('description', $input['description']);
        }
        if (array_key_exists('is_active', $input)) {
            $permission->is_active = (int) $input['is_active'] === 1;
        }
        $permission->save();

        $this->forgetCache();
        $this->activity->log('permissions', 'update', "Updated permission: {$permission->name}", $permission, [], $input);

        return $permission;
    }

    public function deletePermission(Permission $permission): void
    {
        if ($this->isSystemPermission($permission)) {
            throw ApiException::badRequest('Cannot delete a system permission');
        }

        $permission->delete();
        $this->forgetCache();
        $this->activity->log('permissions', 'delete', "Deleted permission: {$permission->name}", $permission);
    }

    private function assertGroupSlugFree(string $slug, ?int $exceptId = null): void
    {
        if ($slug === '') {
            throw ApiException::badRequest('Module name is required');
        }

        $taken = PermissionGroup::query()->where('slug', $slug)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ApiException::conflict('Module with this slug already exists');
        }
    }

    private function isSystemGroup(PermissionGroup $group): bool
    {
        return array_key_exists($group->slug, AdminPermissionCatalogue::groups());
    }

    private function isSystemPermission(Permission $permission): bool
    {
        return array_key_exists($permission->name, AdminPermissionCatalogue::permissions());
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
