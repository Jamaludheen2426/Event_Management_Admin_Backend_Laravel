<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;

/*
| The staff portal and the v1 security rules listed in CLAUDE.md.
*/

beforeEach(function () {
    seedHouse();
    $this->owner = vendorOwner();
});

it('checks staff permissions with the v1 messages', function () {
    $noRole = staffMember($this->owner, null);
    $viewer = staffMember($this->owner, ['staff.view']);

    $this->withToken(accessToken($noRole))->getJson('/api/v1/vendors/staff/portal/staff')
        ->assertStatus(403)->assertJsonPath('message', 'No role assigned. Please contact your vendor.');
    $this->withToken(accessToken($viewer))->getJson('/api/v1/vendors/staff/portal/roles')
        ->assertStatus(403)->assertJsonPath('message', 'Access denied. Insufficient permissions.');
    $this->withToken(accessToken($viewer))->getJson('/api/v1/vendors/staff/portal/staff')
        ->assertOk()->assertJsonPath('data.pagination.totalItems', 2);
});

it('keeps role assignment, passwords and login switches with the owner', function () {
    $manager = staffMember($this->owner, ['staff.view', 'staff.create', 'staff.edit']);
    $token = accessToken($manager);

    $this->withToken($token)->postJson('/api/v1/vendors/staff/portal/staff', ['name' => 'X', 'email' => 'x@ra.test', 'mobile' => '9000000002', 'role_id' => 1])
        ->assertStatus(403)->assertJsonPath('message', 'Role assignment is not allowed from the staff portal. Only the vendor can assign roles.');

    $id = $this->withToken($token)->postJson('/api/v1/vendors/staff/portal/staff', ['name' => 'X', 'email' => 'x@ra.test', 'mobile' => '9000000002'])
        ->assertCreated()->assertJsonPath('message', 'Staff created successfully')->json('data.id');

    $this->withToken($token)->putJson("/api/v1/vendors/staff/portal/staff/{$id}", ['password' => 'Hack#12a', 'login_access' => 0])
        ->assertStatus(403)->assertJsonPath('message', 'Field(s) [password, login_access] can only be changed by the vendor');
    $this->withToken($token)->putJson("/api/v1/vendors/staff/portal/staff/{$id}", ['designation' => 'Lead', 'dob' => '2000-01-01'])
        ->assertOk()->assertJsonPath('data.designation', 'Lead')->assertJsonPath('data.dob', null);

    $this->withToken($token)->patchJson("/api/v1/vendors/staff/portal/staff/{$id}", ['is_active' => 0])->assertStatus(405);
});

it('stops staff from deleting themselves or touching their own role', function () {
    $admin = staffMember($this->owner, ['staff.view', 'staff.delete', 'roles.view', 'roles.edit', 'roles.delete']);
    $token = accessToken($admin);
    $ownRole = Role::query()->where('name', 'staff-role-'.$admin->id)->firstOrFail();

    $this->withToken($token)->deleteJson("/api/v1/vendors/staff/portal/staff/{$admin->id}")
        ->assertStatus(403)->assertJsonPath('message', 'You cannot delete your own account');
    $this->withToken($token)->putJson("/api/v1/vendors/staff/portal/roles/{$ownRole->id}", ['name' => 'Boss'])
        ->assertStatus(403)->assertJsonPath('message', 'You cannot edit your own role');
    $this->withToken($token)->deleteJson("/api/v1/vendors/staff/portal/roles/{$ownRole->id}")
        ->assertStatus(403)->assertJsonPath('message', 'You cannot delete your own role');
    $this->withToken($token)->putJson("/api/v1/vendors/staff/portal/roles/{$ownRole->id}/permissions", ['permissions' => []])
        ->assertStatus(403)->assertJsonPath('message', 'You cannot modify permissions of your own role');
});

it('never lets staff grant permissions they do not hold', function () {
    $editor = staffMember($this->owner, ['roles.view', 'roles.create', 'roles.edit', 'client.view']);
    $token = accessToken($editor);

    $roleId = $this->withToken($token)->postJson('/api/v1/vendors/staff/portal/roles', ['name' => 'Junior'])->assertCreated()->json('data.role.id');
    $held = Permission::query()->where('guard_name', 'vendor')->where('name', 'client.view')->value('id');
    $notHeld = Permission::query()->where('guard_name', 'vendor')->where('name', 'staff.delete')->value('id');

    $this->withToken($token)->putJson("/api/v1/vendors/staff/portal/roles/{$roleId}/permissions", ['permissions' => [$held, $notHeld]])
        ->assertStatus(403)->assertJsonPath('message', 'You cannot assign permissions that you do not have yourself');
    $this->withToken($token)->putJson("/api/v1/vendors/staff/portal/roles/{$roleId}/permissions", ['permissions' => [['permission_id' => $held]]])
        ->assertOk();

    $this->withToken($token)->getJson('/api/v1/vendors/staff/portal/roles')
        ->assertOk()->assertJsonPath('message', 'Roles retrieved successfully')->assertJsonPath('data.pagination.totalItems', 2);
    $this->withToken($token)->getJson('/api/v1/vendors/staff/portal/modules')->assertOk()->assertJsonPath('data.modules.0.permissions.0.module', 'about');
});

it('signs staff out on deactivation and on revoked login access', function () {
    $staff = staffMember($this->owner, ['staff.view']);
    $token = accessToken($staff);
    $ownerToken = accessToken($this->owner);

    $this->withToken($ownerToken)->putJson("/api/v1/vendors/staff/{$staff->id}", ['login_access' => 0])->assertOk();
    $this->withToken($token)->getJson('/api/v1/vendors/staff/auth/me')->assertStatus(401);
});
