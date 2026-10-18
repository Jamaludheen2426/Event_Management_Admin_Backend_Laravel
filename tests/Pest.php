<?php

declare(strict_types=1);

use App\Core\Auth\SessionTokens;
use App\Core\Tenancy\Tenancy;
use App\Modules\Clients\Models\Client;
use App\Modules\Identity\Actions\Roles\ProvisionCompanyRoles;
use App\Modules\Identity\Database\Seeders\PermissionCatalogueSeeder;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Vendors\Database\Seeders\HouseVendorSeeder;
use App\Modules\Vendors\Database\Seeders\VendorPermissionSeeder;
use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
| Feature tests run against MySQL (event_laravel_test), not SQLite: the schema
| relies on MySQL behaviour (generated columns, JSON, collations) and tests must
| see what production sees.
*/
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** The house company + house vendor every install has (ids 1 / 1). */
function seedHouse(): void
{
    (new HouseVendorSeeder)->run();
}

/**
 * An active admin user of the given company (house company by default),
 * holding one of the built-in roles ('developer', 'super_admin', 'admin'),
 * or no role at all with $role = null.
 */
function adminUser(?Company $company = null, array $attributes = [], ?string $role = 'super_admin'): User
{
    $companyId = $company?->id ?? (int) config('tenancy.house_company_id');

    $user = Tenancy::bypass(static fn (): User => User::factory()->create(array_merge([
        'company_id' => $companyId,
    ], $attributes)));

    if ($role !== null) {
        giveRole($user, app(ProvisionCompanyRoles::class)->handle($companyId)[$role]);
    }

    return $user;
}

/** Assign a role under the user's own company (spatie team). */
function giveRole(User $user, Role $role): User
{
    $registrar = app(PermissionRegistrar::class);
    $previous = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($user->company_id);
    $user->syncRoles([$role]);
    $registrar->setPermissionsTeamId($previous);
    $user->unsetRelation('roles');

    return $user;
}

/**
 * A custom admin role of the house company with exactly these permissions
 * ([slug => requires_approval]). Seeds the permission catalogue first.
 *
 * @param  array<string, bool>  $permissions
 */
function customRole(array $permissions, int $level = 10, string $name = 'custom'): Role
{
    (new PermissionCatalogueSeeder)->run();

    $role = Role::query()->create([
        'team_id' => (int) config('tenancy.house_company_id'),
        'name' => $name,
        'guard_name' => 'admin',
        'label' => ucfirst($name),
        'level' => $level,
        'is_active' => true,
        'approval_status' => 'approved',
    ]);

    $ids = Permission::query()->where('guard_name', 'admin')->whereIn('name', array_keys($permissions))->pluck('id', 'name');
    $role->permissions()->sync($ids->mapWithKeys(static fn (int $id, string $slug): array => [$id => ['requires_approval' => $permissions[$slug]]])->all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

/**
 * A vendor with its owner login (password Pass#12a). The house company owns it.
 */
function vendorOwner(array $vendor = [], array $owner = []): VendorUser
{
    return Tenancy::bypass(static function () use ($vendor, $owner): VendorUser {
        $model = Vendor::factory()->create(['company_id' => (int) config('tenancy.house_company_id'), ...$vendor]);

        return VendorUser::factory()->owner()->create(['vendor_id' => $model->id, ...$owner]);
    });
}

/**
 * A staff member of the owner's vendor holding a vendor role with these
 * permission slugs (none = no role at all).
 *
 * @param  list<string>|null  $permissions
 */
function staffMember(VendorUser $owner, ?array $permissions = [], array $attributes = []): VendorUser
{
    (new VendorPermissionSeeder)->run();

    $staff = Tenancy::bypass(static fn (): VendorUser => VendorUser::factory()->create(['vendor_id' => $owner->vendor_id, ...$attributes]));
    if ($permissions === null) {
        return $staff;
    }

    $role = Role::query()->create([
        'team_id' => $owner->vendor_id, 'name' => 'staff-role-'.$staff->id, 'guard_name' => 'vendor',
        'label' => 'Staff role', 'level' => 10, 'is_active' => true, 'approval_status' => 'approved',
    ]);
    $role->permissions()->sync(Permission::query()->where('guard_name', 'vendor')->whereIn('name', $permissions)->pluck('id')->all());

    $registrar = app(PermissionRegistrar::class);
    $previous = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($owner->vendor_id);
    $staff->syncRoles([$role]);
    $registrar->setPermissionsTeamId($previous);
    $registrar->forgetCachedPermissions();

    return $staff->unsetRelation('roles');
}

/** An access token for the user, as the login endpoint would issue it. */
function accessToken(User|VendorUser|Client $user): string
{
    return app(SessionTokens::class)->issueAccess($user);
}

/** A client of the given vendor (house vendor by default), password Pass#12a. */
function client(array $attributes = []): Client
{
    return Tenancy::bypass(static fn (): Client => Client::factory()->create($attributes));
}
