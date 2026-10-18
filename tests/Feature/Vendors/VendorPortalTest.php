<?php

declare(strict_types=1);

use App\Core\Auth\PersonalAccessToken;
use App\Core\Tenancy\Tenancy;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Identity\Mail\PasswordResetCodeMail;
use App\Modules\Identity\Models\Permission;
use App\Modules\Platform\Models\City;
use App\Modules\Platform\Models\Country;
use App\Modules\Platform\Models\District;
use App\Modules\Platform\Models\State;
use App\Modules\Vendors\Database\Seeders\VendorPermissionSeeder;
use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Support\Facades\Mail;

/*
| Vendor owners: sign-in, profile, admin vendor screens, staff management.
*/

beforeEach(function () {
    seedHouse();
});

function place(): array
{
    $country = Country::query()->create(['name' => 'India', 'code' => 'IN']);
    $state = State::query()->create(['country_id' => $country->id, 'name' => 'Tamil Nadu']);
    $district = District::query()->create(['state_id' => $state->id, 'name' => 'Chennai']);
    $city = City::query()->create(['district_id' => $district->id, 'name' => 'Adyar', 'pincode' => '600020']);

    return ['country_id' => $country->id, 'state_id' => $state->id, 'city_id' => $district->id, 'pincode_id' => $city->id];
}

it('signs the owner in with the v1 cookies and answers the v1 vendor object', function () {
    $owner = vendorOwner(['company_name' => 'RA Events', 'phone' => '9876543210'], ['email' => 'Owner@RA.test', 'name' => 'Jamal']);

    $response = $this->postJson('/api/v1/vendors/auth/login', ['email' => 'owner@ra.test', 'password' => 'Pass#12a'])
        ->assertOk()
        ->assertJsonPath('message', 'Login successful')
        ->assertJsonPath('data.vendor.company_name', 'RA Events')
        ->assertJsonPath('data.vendor.company_contact', '9876543210')
        ->assertJsonPath('data.vendor.name', 'Jamal')
        ->assertJsonPath('data.vendor.email', 'owner@ra.test')
        ->assertJsonMissingPath('data.vendor.password');
    expect(collect($response->headers->getCookies())->map->getName()->all())->toEqualCanonicalizing(['vendor_access_token', 'vendor_refresh_token']);

    $this->postJson('/api/v1/vendors/auth/login', ['email' => 'owner@ra.test', 'password' => 'nope'])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid email or password');
    $this->postJson('/api/v1/vendors/auth/login', ['email' => 'owner@ra.test'])
        ->assertStatus(400)->assertJsonPath('message', 'Email and password are required');

    Tenancy::bypass(fn () => Vendor::query()->whereKey($owner->vendor_id)->update(['status' => 'inactive']));
    $this->postJson('/api/v1/vendors/auth/login', ['email' => 'owner@ra.test', 'password' => 'Pass#12a'])
        ->assertStatus(403)->assertJsonPath('message', 'Your account is inactive. Please contact the administrator.');
});

it('keeps each portal to its own login and answers in its own words', function () {
    $owner = vendorOwner();
    $staff = staffMember($owner, ['staff.view']);

    $this->getJson('/api/v1/vendors/auth/me')->assertStatus(401)->assertJsonPath('message', 'Vendor authentication required.');
    $this->getJson('/api/v1/vendors/staff/auth/me')->assertStatus(401)->assertJsonPath('message', 'Staff authentication required.');

    $this->withToken(accessToken($staff))->getJson('/api/v1/vendors/auth/me')->assertStatus(401);
    $this->withToken(accessToken($owner))->getJson('/api/v1/vendors/staff/auth/me')->assertStatus(401);
    $this->withToken(accessToken($owner))->getJson('/api/v1/users')->assertStatus(401);
    $this->withToken(accessToken(adminUser()))->getJson('/api/v1/vendors/auth/me')->assertStatus(401);
});

it('answers /auth/me with location names and the website feature flag', function () {
    $owner = vendorOwner(['website_enabled' => true]);
    $where = place();
    Tenancy::bypass(fn () => Vendor::query()->whereKey($owner->vendor_id)->update(['district_id' => $where['city_id'], 'city_id' => $where['pincode_id']]));

    $this->withToken(accessToken($owner))->getJson('/api/v1/vendors/auth/me')
        ->assertOk()
        ->assertJsonPath('data.city', 'Adyar')
        ->assertJsonPath('data.locality.pincode', '600020')
        ->assertJsonPath('data.features.website_management', false);
});

it('lets the owner edit the business and themselves, validating contacts', function () {
    $owner = vendorOwner();
    $token = accessToken($owner);

    $this->withToken($token)->putJson('/api/v1/vendors/auth/profile', [
        'companyName' => 'New Name', 'companyEmail' => 'HELLO@New.test', 'contact' => '+91 98765 43210', 'accNo' => '1234567890', 'status' => 'inactive',
    ])->assertOk()
        ->assertJsonPath('data.company_name', 'New Name')
        ->assertJsonPath('data.company_email', 'hello@new.test')
        ->assertJsonPath('data.contact', '+91 98765 43210')
        ->assertJsonPath('data.acc_no', '1234567890')
        ->assertJsonPath('data.status', 'active');

    $this->withToken($token)->putJson('/api/v1/vendors/auth/profile', ['company_contact' => 'abc'])
        ->assertStatus(400)->assertJsonPath('message', 'Enter a valid mobile number');
});

it('applies the 8-character password rule and ends every session on a change', function () {
    $owner = vendorOwner();
    $token = accessToken($owner);

    $this->withToken($token)->postJson('/api/v1/vendors/auth/change-password', ['current_password' => 'Pass#12a', 'new_password' => 'Longer#123'])
        ->assertStatus(400)->assertJsonPath('message', 'Password must not exceed 8 characters');
    $this->withToken($token)->postJson('/api/v1/vendors/auth/change-password', ['current_password' => 'wrong', 'new_password' => 'Next#12a'])
        ->assertStatus(400)->assertJsonPath('message', 'Current password is incorrect');
    $this->withToken($token)->postJson('/api/v1/vendors/auth/change-password', ['current_password' => 'Pass#12a', 'new_password' => 'Next#12a'])
        ->assertOk();

    expect(PersonalAccessToken::findToken($token))->toBeNull();
    $this->postJson('/api/v1/vendors/auth/login', ['email' => $owner->email, 'password' => 'Next#12a'])->assertOk();
});

it('resets a forgotten owner password with a hashed emailed code', function () {
    Mail::fake();
    $owner = vendorOwner();

    $this->postJson('/api/v1/vendors/auth/forgot-password', ['email' => $owner->email])
        ->assertOk()->assertJsonPath('message', 'If that email is registered, an OTP has been sent');
    $code = null;
    Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });

    $this->postJson('/api/v1/vendors/auth/reset-password', ['email' => $owner->email, 'otp' => '111111', 'new_password' => 'Next#12a'])
        ->assertStatus(400)->assertJsonPath('message', 'Invalid OTP');
    $this->postJson('/api/v1/vendors/auth/reset-password', ['email' => $owner->email, 'otp' => $code, 'new_password' => 'Next#12a'])
        ->assertOk()->assertJsonPath('message', 'Password reset successfully');
    $this->postJson('/api/v1/vendors/auth/login', ['email' => $owner->email, 'password' => 'Next#12a'])->assertOk();
});

it('hands a signed-in owner over to another app with a one-use token', function () {
    $owner = vendorOwner();

    $token = $this->withToken(accessToken($owner))->postJson('/api/v1/vendors/auth/handoff-token')->assertOk()->json('data.handoff_token');

    $this->postJson('/api/v1/vendors/auth/handoff', ['token' => $token])->assertOk()->assertJsonPath('message', 'Handoff successful');
    $this->postJson('/api/v1/vendors/auth/handoff', ['token' => $token])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid or expired handoff token.');
});

it('answers the vendor subscription from plan types (v1 crashed on a missing model)', function () {
    $gold = PlanType::query()->create(['company_id' => 1, 'name' => 'Gold', 'price' => 100]);
    PlanType::query()->create(['company_id' => 1, 'name' => 'Silver', 'price' => 50]);
    $owner = vendorOwner(['plan_type_id' => $gold->id]);

    $this->withToken(accessToken($owner))->getJson('/api/v1/vendors/subscription')
        ->assertOk()
        ->assertJsonPath('data.type', 'common')
        ->assertJsonPath('data.plans.0.name', 'Gold')
        ->assertJsonCount(2, 'data.all_plans');

    PlanType::query()->create(['company_id' => 1, 'name' => 'Special', 'is_custom' => 1, 'vendor_id' => $owner->vendor_id]);
    $this->withToken(accessToken($owner))->getJson('/api/v1/vendors/subscription')
        ->assertJsonPath('data.type', 'custom')->assertJsonPath('data.plans.0.name', 'Special');
});

it('lets the admin create a vendor whose owner can sign in, list it, and open its portal', function () {
    $where = place();
    PlanType::query()->create(['company_id' => 1, 'name' => 'Gold']);
    $admin = accessToken(adminUser());

    $this->withToken($admin)->postJson('/api/v1/vendors', ['companyName' => 'Acme Events', 'name' => 'Asha', 'email' => 'asha@acme.test', 'password' => 'Pass#12a', 'membership' => 'gold', 'gstNo' => 'GST1', ...$where])
        ->assertCreated()
        ->assertJsonPath('data.company_name', 'Acme Events')
        ->assertJsonPath('data.membership', 'Gold')
        ->assertJsonPath('data.gst_no', 'GST1')
        ->assertJsonPath('data.city_id', $where['city_id']);

    $this->withToken($admin)->postJson('/api/v1/vendors', ['company_name' => 'Dup', 'name' => 'X', 'email' => 'asha@acme.test', 'password' => 'Pass#12a', ...$where])
        ->assertStatus(409)->assertJsonPath('message', 'A vendor with this email already exists');
    $this->withToken($admin)->postJson('/api/v1/vendors', ['company_name' => 'NoPlace', 'name' => 'X', 'email' => 'x@x.test', 'password' => 'Pass#12a'])
        ->assertStatus(400)->assertJsonPath('message', 'Country is required');

    $this->postJson('/api/v1/vendors/auth/login', ['email' => 'asha@acme.test', 'password' => 'Pass#12a'])->assertOk();

    $id = $this->withToken($admin)->getJson('/api/v1/vendors?search=asha')
        ->assertOk()->assertJsonPath('data.pagination.totalItems', 1)->json('data.data.0.id');

    $impersonation = $this->withToken($admin)->postJson("/api/v1/vendors/{$id}/impersonate")
        ->assertOk()->assertJsonPath('data.vendor_id', $id);
    expect(collect($impersonation->headers->getCookies())->map->getName())->toContain('vendor_access_token');

    $this->withToken($admin)->patchJson("/api/v1/vendors/{$id}/status", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->postJson('/api/v1/vendors/auth/login', ['email' => 'asha@acme.test', 'password' => 'Pass#12a'])->assertStatus(403);
});

it('lets the owner manage staff, roles and departments of their own vendor only', function () {
    $owner = vendorOwner();
    $other = vendorOwner();
    $token = accessToken($owner);
    (new VendorPermissionSeeder)->run();

    $role = $this->withToken($token)->postJson('/api/v1/vendors/roles', ['name' => 'Front Desk'])
        ->assertCreated()->assertJsonPath('data.role.slug', 'front_desk')->assertJsonPath('data.role.level', 10)
        ->assertJsonPath('data.role.vendor_id', $owner->vendor_id)->json('data.role');
    $view = Permission::query()->where('guard_name', 'vendor')->where('name', 'client.view')->value('id');
    $this->withToken($token)->putJson("/api/v1/vendors/roles/{$role['id']}/permissions", ['permissions' => [['permissionId' => $view]]])
        ->assertOk()->assertJsonPath('data.role.permissions.0.slug', 'client.view');

    $department = $this->withToken($token)->postJson('/api/v1/vendors/departments', ['name' => 'Sales'])->assertCreated()->json('data.department.id');

    $staff = $this->withToken($token)->postJson('/api/v1/vendors/staff', [
        'name' => 'Ravi', 'email' => 'Ravi@RA.test', 'mobile' => '9000000001', 'password' => 'Ravi#12a', 'roleId' => $role['id'], 'departmentId' => $department,
    ])->assertCreated()->assertJsonPath('message', 'Staff member created successfully')
        ->assertJsonPath('data.emp_id', 'EMP-0001')->assertJsonPath('data.role_id', $role['id'])->json('data');

    $this->withToken($token)->getJson('/api/v1/vendors/staff')->assertJsonPath('data.pagination.totalItems', 1)->assertJsonPath('data.data.0.email', 'ravi@ra.test');
    $this->withToken(accessToken($other))->getJson("/api/v1/vendors/staff/{$staff['id']}")->assertStatus(404);
    $this->withToken(accessToken($other))->getJson('/api/v1/vendors/roles')->assertJsonPath('pagination.totalItems', 0);

    $this->withToken($token)->deleteJson("/api/v1/vendors/roles/{$role['id']}")
        ->assertStatus(400)->assertJsonPath('message', 'Cannot delete role: 1 staff member is still assigned. Reassign them first.');

    $this->postJson('/api/v1/vendors/staff/auth/login', ['email' => 'ravi@ra.test', 'password' => 'Ravi#12a'])
        ->assertOk()->assertJsonPath('data.staff.role.permissions.0.slug', 'client.view')->assertJsonPath('data.staff.vendor.id', $owner->vendor_id);

    $this->withToken($token)->patchJson("/api/v1/vendors/staff/{$staff['id']}/status", ['is_active' => 0])->assertOk()->assertJsonPath('data.is_active', 0);
    $this->postJson('/api/v1/vendors/staff/auth/login', ['email' => 'ravi@ra.test', 'password' => 'Ravi#12a'])
        ->assertStatus(403)->assertJsonPath('message', 'Your account is inactive. Please contact your vendor.');
});

it('does not let the admin list include the house vendor deletion', function () {
    $this->withToken(accessToken(adminUser()))->deleteJson('/api/v1/vendors/'.Vendor::houseId())
        ->assertStatus(400)->assertJsonPath('message', 'The house vendor cannot be deleted');
    expect(Tenancy::bypass(fn () => VendorUser::query()->count()))->toBe(0);
});
