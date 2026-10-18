<?php

declare(strict_types=1);

use App\Core\Auth\PersonalAccessToken;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Mail\PasswordResetCodeMail;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| /api/v1/auth — the admin sign-in flow, in v1 shapes and words.
*/

beforeEach(function () {
    seedHouse();
});

it('signs in, sets the v1 cookies and returns the user with role, company and permission slugs', function () {
    adminUser(attributes: ['email' => 'boss@example.com']);

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'Secret#1'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login successful')
        ->assertJsonPath('data.user.email', 'boss@example.com')
        ->assertJsonPath('data.user.role.slug', 'super_admin')
        ->assertJsonPath('data.user.role.name', 'Super Admin')
        ->assertJsonPath('data.user.company.id', 1)
        ->assertJsonMissingPath('data.user.password');

    expect($response->headers->getCookies())->toHaveCount(2)
        ->and(collect($response->headers->getCookies())->map->getName()->all())->toEqualCanonicalizing(['access_token', 'refresh_token'])
        ->and(collect($response->headers->getCookies())->every->isHttpOnly())->toBeTrue();
});

it('gives the same answer for an unknown email and a wrong password', function () {
    adminUser(attributes: ['email' => 'boss@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'boss@example.com', 'password' => 'wrong'])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid email or password');
    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'Secret#1'])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid email or password');
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid email or password');
});

it('refuses inactive, pending and access-revoked accounts with the v1 messages', function (array $state, string $message) {
    adminUser(attributes: ['email' => 'x@example.com', ...$state]);

    $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'Secret#1'])
        ->assertStatus(403)->assertJsonPath('message', $message);
})->with([
    'inactive' => [['is_active' => false], 'Your account is not active. Please contact admin.'],
    'pending approval' => [['approval_status' => 'pending'], 'Your account is not active. Please contact admin.'],
    'login access revoked' => [['login_access' => false], 'Your login access has been revoked. Please contact admin.'],
]);

it('accepts a password hashed by the Node API and re-hashes it with Laravel bcrypt', function () {
    $user = adminUser(attributes: ['email' => 'legacy@example.com']);
    // bcryptjs output for 'Secret#1' (the "$2a$" prefix Laravel would otherwise refuse).
    DB::table('users')->where('id', $user->id)->update(['password' => '$2a$10$yblAcrJ7g7eVtZ2GtWfcw.73XrpHih3bdjnlalNq8ly/8Qd13SUle']);

    $this->postJson('/api/v1/auth/login', ['email' => 'legacy@example.com', 'password' => 'Secret#1'])->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('password'))->toStartWith('$2y$');
});

it('lists the role permission slugs for the frontend PermissionGuard', function () {
    $user = adminUser(role: null);
    giveRole($user, customRole(['employees.view' => false, 'roles.view' => false]));

    $this->withToken(accessToken($user))->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.role.slug', 'custom')
        ->assertJsonPath('data.user.permissions', ['employees.view', 'roles.view']);
});

it('ends the session on logout', function () {
    $user = adminUser();
    $token = accessToken($user);

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('message', 'Logout successful');

    expect(PersonalAccessToken::findToken($token))->toBeNull();
});

it('refreshes with the refresh cookie and never accepts a refresh token as a bearer token', function () {
    adminUser(attributes: ['email' => 'r@example.com']);
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'r@example.com', 'password' => 'Secret#1']);
    $refresh = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'refresh_token')->getValue();

    $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->postJson('/api/v1/auth/refresh')
        ->assertOk()->assertJsonStructure(['data' => ['accessToken', 'token']]);

    $this->withToken($refresh)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('renews an expired access cookie from the refresh cookie on any request (v1 behaviour)', function () {
    adminUser(attributes: ['email' => 'auto@example.com']);
    $login = $this->postJson('/api/v1/auth/login', ['email' => 'auto@example.com', 'password' => 'Secret#1']);
    $refresh = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === 'refresh_token')->getValue();
    $this->app['auth']->forgetGuards();

    $response = $this->withCredentials()->withUnencryptedCookie('refresh_token', $refresh)->getJson('/api/v1/auth/me')->assertOk();

    expect(collect($response->headers->getCookies())->map->getName())->toContain('access_token');
});

it('changes the password, enforces the password rule and signs every session out', function () {
    $user = adminUser();
    $token = accessToken($user);

    $this->withToken($token)->putJson('/api/v1/auth/change-password', ['current_password' => 'nope', 'new_password' => 'Better#22'])
        ->assertStatus(400)->assertJsonPath('message', 'Current password is incorrect');
    $this->withToken($token)->putJson('/api/v1/auth/change-password', ['current_password' => 'Secret#1', 'new_password' => 'weak'])
        ->assertStatus(400);
    $this->withToken($token)->putJson('/api/v1/auth/change-password', ['current_password' => 'Secret#1', 'new_password' => 'Has Space#1'])
        ->assertStatus(400)->assertJsonPath('message', 'Password must not contain spaces');

    $this->withToken($token)->putJson('/api/v1/auth/change-password', ['current_password' => 'Secret#1', 'new_password' => 'Better#22'])
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('resets a forgotten password with an emailed code, stored hashed and valid 10 minutes', function () {
    Mail::fake();
    adminUser(attributes: ['email' => 'f@example.com']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'f@example.com'])->assertOk()->assertJsonPath('message', 'OTP sent to your email');
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk()->assertJsonPath('message', 'OTP sent to your email');

    $code = null;
    Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('f@example.com');
    });
    Mail::assertSentCount(1);
    expect(DB::table('password_reset_tokens')->value('token'))->not->toBe($code);

    $this->postJson('/api/v1/auth/verify-reset-otp', ['email' => 'f@example.com', 'otp' => '000000'])
        ->assertStatus(400)->assertJsonPath('message', 'Invalid OTP');
    $this->postJson('/api/v1/auth/verify-reset-otp', ['email' => 'f@example.com', 'otp' => $code])
        ->assertOk()->assertJsonPath('message', 'OTP verified successfully');
    $this->postJson('/api/v1/auth/reset-password', ['email' => 'f@example.com', 'otp' => $code, 'password' => 'Brand#New1'])
        ->assertOk()->assertJsonPath('message', 'Password reset successfully');

    $this->postJson('/api/v1/auth/login', ['email' => 'f@example.com', 'password' => 'Brand#New1'])->assertOk();
});

it('rejects an expired reset code', function () {
    Mail::fake();
    adminUser(attributes: ['email' => 'e@example.com']);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'e@example.com']);
    $code = null;
    Mail::assertSent(PasswordResetCodeMail::class, function (PasswordResetCodeMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });

    $this->travel(11)->minutes();

    $this->postJson('/api/v1/auth/verify-reset-otp', ['email' => 'e@example.com', 'otp' => $code])
        ->assertStatus(400)->assertJsonPath('message', 'OTP has expired');
});

it('lets the admin edit only their own name, phone, avatar and timezone', function () {
    $user = adminUser();

    $this->withToken(accessToken($user))->putJson('/api/v1/auth/update-profile', [
        'fullName' => 'New Name', 'timezone' => 'Asia/Kolkata', 'email' => 'hijack@example.com',
    ])->assertOk()->assertJsonPath('data.user.full_name', 'New Name');

    expect(Tenancy::bypass(fn () => User::query()->find($user->id)))
        ->full_name->toBe('New Name')
        ->timezone->toBe('Asia/Kolkata')
        ->email->not->toBe('hijack@example.com');
});
