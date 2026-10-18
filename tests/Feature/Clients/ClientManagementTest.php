<?php

declare(strict_types=1);

use App\Core\Approvals\ApprovalRequest;
use App\Modules\Clients\Models\Client;
use App\Modules\Identity\Mail\PasswordResetCodeMail;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
| Clients managed by others: the admin (/website-clients), the vendor owner
| (/vendors/clients, /vendors/subscribers), staff (/vendors/staff/portal/clients),
| and the vendor's public site + the v1 vendor-client portal.
*/

beforeEach(function () {
    seedHouse();
});

/** @return array<string, string> a valid vendor-side client form */
function vendorClientForm(array $overrides = []): array
{
    return [
        'name' => 'Ravi', 'email' => 'ravi@ra.test', 'mobile' => '9555555555', 'address' => '1 Main St',
        'country' => 'India', 'state' => 'TN', 'district' => 'Chennai', 'city' => 'Chennai', 'locality' => 'Adyar',
        'pincode' => '600020', ...$overrides,
    ];
}

it('lets the admin manage clients of every vendor of the company', function () {
    $token = accessToken(adminUser());
    $other = vendorOwner();
    client(['name' => 'House client', 'source' => 'website']);
    client(['name' => 'Other vendor client', 'vendor_id' => $other->vendor_id, 'source' => 'vendor', 'password' => null]);

    $this->withToken($token)->getJson('/api/v1/website-clients')
        ->assertOk()->assertJsonPath('pagination.totalItems', 2)->assertJsonPath('data.0.vendor.id', fn ($id) => is_int($id));
    $this->withToken($token)->getJson('/api/v1/website-clients?vendor_id='.$other->vendor_id)->assertJsonPath('pagination.totalItems', 1);
    $this->withToken($token)->getJson('/api/v1/website-clients?source=website')->assertJsonPath('data.0.name', 'House client');

    $this->withToken($token)->postJson('/api/v1/website-clients', ['name' => 'Nina', 'email' => 'nina@ra.test'])
        ->assertStatus(400)->assertJsonPath('message', 'A password is required so the client can sign in to the portal.');
    $id = $this->withToken($token)->postJson('/api/v1/website-clients', ['name' => 'Nina', 'email' => 'nina@ra.test', 'password' => 'Longpass1'])
        ->assertCreated()->assertJsonPath('data.client.source', 'admin')->assertJsonPath('data.client.vendor_id', Vendor::houseId())->json('data.client.id');

    $this->withToken($token)->putJson("/api/v1/website-clients/{$id}", ['name' => 'Nina K'])->assertOk()->assertJsonPath('data.client.name', 'Nina K');
    $this->withToken($token)->patchJson("/api/v1/website-clients/{$id}/status", ['is_active' => 2])->assertOk()->assertJsonPath('data.client.is_active', 2);

    $this->withToken($token)->getJson('/api/v1/website-clients/stats')
        ->assertOk()->assertJsonPath('data.stats.total', 3)->assertJsonPath('data.stats.blocked', 1)->assertJsonPath('data.stats.cannot_sign_in', 0);

    $this->withToken($token)->deleteJson("/api/v1/website-clients/{$id}")->assertOk()->assertJsonPath('message', 'Client deleted successfully');
    $this->withToken($token)->getJson("/api/v1/website-clients/{$id}")->assertNotFound();
});

it('sends admin client changes for approval without storing the password in plain text', function () {
    $role = customRole(['website_clients.view' => false, 'website_clients.create' => true]);
    $token = accessToken(giveRole(adminUser(role: null), $role));

    $this->withToken($token)->postJson('/api/v1/website-clients', ['name' => 'Pending', 'email' => 'p@ra.test', 'password' => 'Longpass1'])
        ->assertStatus(202);

    $request = ApprovalRequest::query()->latest('id')->firstOrFail();
    $stored = (string) data_get($request->request_data, 'password');
    expect($stored)->not->toBe('Longpass1')->and(Hash::check('Longpass1', $stored))->toBeTrue();

    $pending = Client::query()->withoutGlobalScopes()->where('email', 'p@ra.test')->firstOrFail();
    expect($pending->is_active)->toBe(0);
    $this->postJson('/api/v1/public/website-clients/login', ['email' => 'p@ra.test', 'password' => 'Longpass1'])->assertStatus(403);
});

it('lets a vendor manage only its own clients, with the vendor-side rules', function () {
    $owner = vendorOwner();
    $token = accessToken($owner);
    $foreign = client(['email' => 'foreign@ra.test']);

    $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['pincode' => '']))
        ->assertStatus(400)->assertJsonPath('message', 'Pincode is required');
    $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['locality' => '___']))
        ->assertStatus(400)->assertJsonPath('message', 'Locality cannot contain only special characters or underscores');
    $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['password' => 'Longpass1']))
        ->assertStatus(400)->assertJsonPath('message', 'Password must contain exactly 8 characters');

    $created = $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['client_type' => 'subscribed', 'registration_type' => 'Guest']))
        ->assertCreated()->assertJsonPath('message', 'Client created successfully')
        ->assertJsonPath('data.client_type', 'subscribed')->assertJsonPath('data.registration_type', 'guest')
        ->assertJsonPath('data.login_access', 0)->json('data');
    expect($created['client_id'])->toStartWith('CLI-');

    $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['mobile' => '9666666666']))
        ->assertStatus(409)->assertJsonPath('message', 'A client with this email is already registered.');

    $this->withToken($token)->getJson('/api/v1/vendors/clients?plan=guest')
        ->assertOk()->assertJsonPath('data.pagination.totalItems', 1)->assertJsonPath('data.data.0.email', 'ravi@ra.test');
    $this->withToken($token)->getJson('/api/v1/vendors/clients?search='.$created['id'])->assertJsonPath('data.pagination.totalItems', 1);

    // The house vendor's client is invisible here.
    $this->withToken($token)->getJson("/api/v1/vendors/clients/{$foreign->id}")->assertNotFound()->assertJsonPath('message', 'Client not found');
    $this->withToken($token)->deleteJson("/api/v1/vendors/clients/{$foreign->id}")->assertNotFound();

    $this->withToken($token)->putJson("/api/v1/vendors/clients/{$created['id']}", ['city' => 'Madurai', 'login_access' => 1])
        ->assertOk()->assertJsonPath('data.city', 'Madurai')->assertJsonPath('data.login_access', 1);
    $this->withToken($token)->patchJson("/api/v1/vendors/clients/{$created['id']}/status", ['is_active' => 0])->assertOk()->assertJsonPath('data.is_active', 0);
    $this->withToken($token)->getJson("/api/v1/vendors/clients/{$created['id']}")->assertOk()->assertJsonPath('data.name', 'Ravi');
});

it('checks client permissions in the staff portal', function () {
    $owner = vendorOwner();
    $viewer = accessToken(staffMember($owner, ['client.view']));
    $editor = accessToken(staffMember($owner, ['client.view', 'client.create', 'client.edit']));

    $this->withToken($viewer)->postJson('/api/v1/vendors/staff/portal/clients', vendorClientForm())
        ->assertStatus(403)->assertJsonPath('message', 'Access denied. Insufficient permissions.');
    $id = $this->withToken($editor)->postJson('/api/v1/vendors/staff/portal/clients', vendorClientForm())->assertCreated()->json('data.id');

    $this->withToken($viewer)->getJson("/api/v1/vendors/staff/portal/clients/{$id}")->assertOk()->assertJsonPath('data.client.id', $id);
    $this->withToken($editor)->patchJson("/api/v1/vendors/staff/portal/clients/{$id}", ['is_active' => 0])->assertOk()->assertJsonPath('data.is_active', 0);
    $this->withToken($editor)->deleteJson("/api/v1/vendors/staff/portal/clients/{$id}")->assertStatus(403);
});

it('signs up on a vendor site and hands off to the vendor-client portal', function () {
    $owner = vendorOwner(['company_name' => 'Star Events & Co']);
    Mail::fake();

    $this->postJson('/api/v1/public/vendors/no-such-vendor/register-client', [])->assertNotFound()->assertJsonPath('message', 'Vendor not found');
    $this->postJson('/api/v1/public/vendors/star-events-co/register-client', ['name' => 'Mia', 'email' => 'mia@ra.test', 'mobile' => '9777777777', 'password' => 'Mia#2026', 'subscribe_newsletter' => true])
        ->assertCreated()->assertJsonPath('message', 'Registration completed successfully')->assertJsonPath('data.client_type', 'subscribed');

    $this->postJson('/api/v1/public/vendors/star-events-co/login-client', ['email' => 'mia@ra.test', 'password' => 'wrong'])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid email or password');
    $handoff = $this->postJson('/api/v1/public/vendors/star-events-co/login-client', ['email' => 'mia@ra.test', 'password' => 'Mia#2026'])
        ->assertOk()->json('data.handoff_token');

    $this->postJson('/api/v1/vendors/client/auth/handoff', ['token' => $handoff])
        ->assertOk()->assertCookie('client_access_token')->assertJsonPath('data.client.vendor.company_name', 'Star Events & Co');
    $this->postJson('/api/v1/vendors/client/auth/handoff', ['token' => $handoff])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid or expired client login handoff.');

    $mia = Client::query()->withoutGlobalScopes()->where('email', 'mia@ra.test')->firstOrFail();
    $token = accessToken($mia);
    $this->withToken($token)->getJson('/api/v1/vendors/client/auth/me')->assertOk()->assertJsonPath('data.client.email', 'mia@ra.test');
    $this->withToken($token)->getJson('/api/v1/vendors/client/subscription/plans')->assertOk()->assertJsonPath('data.type', 'common');
    $this->withToken($token)->putJson('/api/v1/vendors/client/auth/profile', ['city' => 'Salem', 'email' => 'hijack@ra.test'])
        ->assertOk()->assertJsonPath('data.client.city', 'Salem')->assertJsonPath('data.client.email', 'mia@ra.test');

    // The newsletter list got the e-mail.
    $this->withToken(accessToken($owner))->getJson('/api/v1/vendors/subscribers')->assertJsonPath('data.data.0.email', 'mia@ra.test');

    // Forgot password: the code is e-mailed, never returned.
    $this->postJson('/api/v1/vendors/client/auth/forgot-password', ['email' => 'mia@ra.test'])
        ->assertOk()->assertJsonMissingPath('data.reset_code');
    $code = null;
    Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });
    $this->postJson('/api/v1/vendors/client/auth/reset-password', ['email' => 'mia@ra.test', 'reset_code' => $code, 'new_password' => 'New#2026'])
        ->assertOk()->assertJsonPath('message', 'Password reset successfully. You can now log in.');
    $this->withToken($token)->getJson('/api/v1/vendors/client/auth/me')->assertStatus(401);
    $this->postJson('/api/v1/public/vendors/star-events-co/login-client', ['email' => 'mia@ra.test', 'password' => 'New#2026'])->assertOk();
});

it('keeps vendor clients without login access out of every portal', function () {
    $owner = vendorOwner(['company_name' => 'Quiet']);
    $c = client(['vendor_id' => $owner->vendor_id, 'email' => 'q@ra.test', 'login_access' => false]);

    $this->postJson('/api/v1/public/vendors/quiet/login-client', ['email' => 'q@ra.test', 'password' => 'Pass#12a'])
        ->assertStatus(403)->assertJsonPath('message', 'Login access is not enabled. Please contact the vendor.');
    $this->withToken(accessToken($c))->getJson('/api/v1/vendors/client/auth/me')
        ->assertStatus(403)->assertJsonPath('message', 'Your login access has been revoked.');
});

it('keeps the newsletter list per vendor', function () {
    $token = accessToken(vendorOwner());

    $id = $this->withToken($token)->postJson('/api/v1/vendors/subscribers', ['email' => ' News@RA.test '])
        ->assertCreated()->assertJsonPath('data.email', 'news@ra.test')->json('data.id');
    $this->withToken($token)->postJson('/api/v1/vendors/subscribers', ['email' => 'news@ra.test'])
        ->assertStatus(409)->assertJsonPath('message', 'This email is already subscribed.');
    $this->withToken($token)->putJson("/api/v1/vendors/subscribers/{$id}", ['is_active' => 0])->assertOk()->assertJsonPath('data.is_active', false);

    $this->withToken(accessToken(vendorOwner()))->putJson("/api/v1/vendors/subscribers/{$id}", ['is_active' => 1])->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/vendors/subscribers/{$id}")->assertOk();
    $this->withToken($token)->postJson('/api/v1/vendors/subscribers', ['email' => 'news@ra.test'])->assertCreated();
});

it('lets a vendor add clients who share a mobile number (v1 vendor clients could)', function () {
    $owner = vendorOwner();
    $token = accessToken($owner);
    $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['email' => 'a@ra.test', 'mobile' => '9555555555']))->assertCreated();
    $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['email' => 'b@ra.test', 'mobile' => '9555555555']))->assertCreated();
    expect(Client::query()->withoutGlobalScopes()->where('mobile', '9555555555')->count())->toBe(2);

    // The same e-mail is still refused.
    $this->withToken($token)->postJson('/api/v1/vendors/clients', vendorClientForm(['email' => 'a@ra.test', 'mobile' => '9555555556']))->assertStatus(409);
});
