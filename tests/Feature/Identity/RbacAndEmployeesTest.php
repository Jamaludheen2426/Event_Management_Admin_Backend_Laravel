<?php

declare(strict_types=1);

use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Actions\Roles\ProvisionCompanyRoles;
use App\Modules\Identity\Database\Seeders\PermissionCatalogueSeeder;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\Country;
use App\Modules\Platform\Models\District;
use App\Modules\Platform\Models\State;

/*
| Permission checks, the role hierarchy and the employees module (v1 /users).
*/

beforeEach(function () {
    seedHouse();
});

function employee(array $permissions, int $level = 10): User
{
    $user = adminUser(role: null);

    return giveRole($user, customRole($permissions, $level));
}

it('refuses a route whose permission the role lacks, with the v1 message', function () {
    $this->withToken(accessToken(employee(['roles.view' => false])))->getJson('/api/v1/users')
        ->assertStatus(403)->assertJsonPath('message', 'Access denied. Insufficient permissions.');
});

it('lets developer and super admin through every check', function (string $role) {
    $this->withToken(accessToken(adminUser(role: $role)))->getJson('/api/v1/users')->assertOk();
})->with(['developer', 'super_admin']);

it('locks out a user whose role was switched off', function () {
    $user = employee(['employees.view' => false]);
    Role::query()->where('name', 'custom')->update(['is_active' => false]);

    $this->withToken(accessToken($user))->getJson('/api/v1/users')
        ->assertStatus(403)->assertJsonPath('message', 'Your role is inactive. Please contact admin.');
});

it('locks out the users of a suspended company', function () {
    $user = adminUser();
    Company::query()->whereKey(1)->update(['is_active' => false]);

    $this->withToken(accessToken($user))->getJson('/api/v1/auth/me')
        ->assertStatus(403)->assertJsonPath('message', 'Your company account is suspended. Please contact support.');
});

it('lists employees in v1 keys, location ids translated back to v1 names', function () {
    $country = Country::query()->create(['name' => 'India', 'code' => 'IN']);
    $state = State::query()->create(['country_id' => $country->id, 'name' => 'Tamil Nadu']);
    $district = District::query()->create(['state_id' => $state->id, 'name' => 'Chennai']);
    $department = Tenancy::bypass(fn () => Department::query()->create(['company_id' => 1, 'name' => 'Ops']));
    adminUser(attributes: ['district_id' => $district->id, 'department_id' => $department->id, 'email' => 'e1@example.com']);

    $this->withToken(accessToken(adminUser()))->getJson('/api/v1/users?search=e1@')
        ->assertOk()
        ->assertJsonPath('pagination.totalItems', 1)
        ->assertJsonPath('data.0.city_id', $district->id)
        ->assertJsonPath('data.0.department', 'Ops')
        ->assertJsonPath('data.0.role.slug', 'super_admin')
        ->assertJsonPath('data.0.is_active', 1)
        ->assertJsonPath('data.0.has_pending_approval', false);
});

it('creates an employee from the v1 form keys and assigns the role', function () {
    $admin = Tenancy::bypass(fn () => adminUser());
    $roles = app(ProvisionCompanyRoles::class)->handle(1);

    $this->withToken(accessToken($admin))->postJson('/api/v1/users', [
        'fullName' => 'New Person', 'email' => 'new@example.com', 'password' => 'Strong#Pass1',
        'confirmPassword' => 'Strong#Pass1', 'roleId' => $roles['admin']->id, 'isActive' => 1, 'loginAccess' => 1,
        'department' => 'free text ignored',
    ])->assertCreated()
        ->assertJsonPath('message', 'User created successfully')
        ->assertJsonPath('data.user.role.slug', 'admin')
        ->assertJsonPath('data.user.is_active', 1);

    $this->postJson('/api/v1/auth/login', ['email' => 'new@example.com', 'password' => 'Strong#Pass1'])->assertOk();
});

it('rejects a duplicate email with 409 and a weak password with 400', function () {
    $admin = adminUser(attributes: ['email' => 'taken@example.com']);
    $roles = app(ProvisionCompanyRoles::class)->handle(1);
    $token = accessToken($admin);

    $this->withToken($token)->postJson('/api/v1/users', [
        'full_name' => 'X', 'email' => 'taken@example.com', 'password' => 'Strong#Pass1', 'role_id' => $roles['admin']->id,
    ])->assertStatus(409)->assertJsonPath('message', 'Email already exists');

    $this->withToken($token)->postJson('/api/v1/users', [
        'full_name' => 'X', 'email' => 'other@example.com', 'password' => 'short', 'role_id' => $roles['admin']->id,
    ])->assertStatus(400);
});

it('frees the email of a deleted employee for reuse (no more renaming on delete)', function () {
    $admin = adminUser();
    $gone = adminUser(attributes: ['email' => 'reuse@example.com'], role: 'admin');
    $roles = app(ProvisionCompanyRoles::class)->handle(1);

    $this->withToken(accessToken($admin))->deleteJson("/api/v1/users/{$gone->id}")->assertOk();
    $this->withToken(accessToken($admin))->postJson('/api/v1/users', [
        'full_name' => 'Again', 'email' => 'reuse@example.com', 'password' => 'Strong#Pass1', 'role_id' => $roles['admin']->id,
    ])->assertCreated();
});

it('never lets an editor hand out a role above their own level, on create or on edit', function () {
    $editor = employee(['employees.create' => false, 'employees.edit' => false, 'employees.view' => false], level: 25);
    $roles = app(ProvisionCompanyRoles::class)->handle(1);
    $junior = adminUser(role: null);
    giveRole($junior, Role::query()->where('name', 'custom')->firstOrFail());
    $token = accessToken($editor);

    $this->withToken($token)->postJson('/api/v1/users', [
        'full_name' => 'X', 'email' => 'x@example.com', 'password' => 'Strong#Pass1', 'role_id' => $roles['admin']->id,
    ])->assertStatus(403)->assertJsonPath('message', 'Cannot assign a role with higher level than your own');

    // v1 skipped this check on edit.
    $this->withToken($token)->putJson("/api/v1/users/{$junior->id}", ['role_id' => $roles['super_admin']->id])
        ->assertStatus(403);
});

it('signs a deactivated employee out at once', function () {
    $admin = adminUser();
    $target = adminUser(role: 'admin');
    $targetToken = accessToken($target);

    $this->withToken(accessToken($admin))->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => 0])
        ->assertOk()->assertJsonPath('data.user.is_active', 0);

    $this->app['auth']->forgetGuards();
    $this->withToken($targetToken)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('does not let an admin delete or deactivate themselves', function () {
    $admin = adminUser();
    $token = accessToken($admin);

    $this->withToken($token)->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(400);
    $this->withToken($token)->patchJson("/api/v1/users/{$admin->id}/status", ['is_active' => 0])->assertStatus(400);
});

it('hides other companies\' employees', function () {
    $other = Company::factory()->create();
    $stranger = adminUser($other);

    $this->withToken(accessToken(adminUser()))->getJson("/api/v1/users/{$stranger->id}")
        ->assertStatus(404)->assertJsonPath('message', 'User not found');
});

it('manages roles: v1 name/slug keys, permission grants with approval flags, and safe deletes', function () {
    $admin = adminUser();
    (new PermissionCatalogueSeeder)->run();
    $token = accessToken($admin);

    $roleId = $this->withToken($token)->postJson('/api/v1/roles', ['name' => 'Sub Admin', 'level' => 25])
        ->assertCreated()
        ->assertJsonPath('data.role.slug', 'sub_admin')
        ->assertJsonPath('data.role.name', 'Sub Admin')
        ->json('data.role.id');

    $view = Permission::query()->where('name', 'employees.view')->value('id');
    $edit = Permission::query()->where('name', 'employees.edit')->value('id');

    $this->withToken($token)->postJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => [
        ['permissionId' => $view, 'requiresApproval' => false],
        ['permissionId' => $edit, 'requiresApproval' => true],
    ]])->assertOk()
        ->assertJsonCount(2, 'data.role.permissions')
        ->assertJsonPath('data.role.permissions.1.RolePermission.requires_approval', true);

    $member = adminUser(role: null);
    giveRole($member, Role::query()->findOrFail($roleId));

    $this->withToken($token)->deleteJson("/api/v1/roles/{$roleId}")
        ->assertStatus(400)
        ->assertJsonPath('message', 'Cannot delete role: 1 employee is still assigned to this role. Reassign or delete them first.');

    $superAdmin = Role::query()->where('name', 'super_admin')->value('id');
    $this->withToken($token)->deleteJson("/api/v1/roles/{$superAdmin}")->assertStatus(400);
});

it('does not let an editor grant permissions they do not hold', function () {
    $editor = employee(['roles.manage' => false, 'roles.view' => false], level: 50);
    (new PermissionCatalogueSeeder)->run();
    $lower = Role::query()->create(['team_id' => 1, 'name' => 'lower', 'guard_name' => 'admin', 'label' => 'Lower', 'level' => 5, 'is_active' => true, 'approval_status' => 'approved']);
    $companies = Permission::query()->where('name', 'companies.delete')->value('id');

    $this->withToken(accessToken($editor))->postJson("/api/v1/roles/{$lower->id}/permissions", ['permission_ids' => [$companies]])
        ->assertStatus(403);
});
