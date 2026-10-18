<?php

declare(strict_types=1);

use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientSession;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialUser;
use PragmaRX\Google2FA\Google2FA;

/*
| Client sign-in: /api/v1/public/website-clients/* (v1 websiteClient.controller).
*/

beforeEach(function () {
    seedHouse();
});

it('registers and signs in with the v1 messages and cookies', function () {
    $this->postJson('/api/v1/public/website-clients/register', ['name' => 'Asha', 'email' => 'Asha@Ra.test', 'password' => 'weak'])
        ->assertStatus(400)->assertJsonPath('message', 'Password must be at least 8 characters.');

    $this->postJson('/api/v1/public/website-clients/register', ['name' => 'Asha', 'email' => 'Asha@Ra.test', 'password' => 'Longpass1', 'mobile' => '+91 98765 43210'])
        ->assertCreated()->assertJsonPath('data.client.email', 'asha@ra.test')->assertJsonPath('data.client.source', 'website')
        ->assertJsonPath('data.client.has_password', 1)->assertJsonMissingPath('data.client.password');

    $this->postJson('/api/v1/public/website-clients/register', ['name' => 'B', 'email' => 'x@ra.test', 'password' => 'Longpass1', 'mobile' => '9876543210'])
        ->assertStatus(400)->assertJsonPath('message', 'An account with this mobile number already exists.');

    $this->postJson('/api/v1/public/website-clients/login', ['email' => 'asha@ra.test', 'password' => 'nope'])
        ->assertStatus(401)->assertJsonPath('message', 'Invalid email or password.');

    $this->postJson('/api/v1/public/website-clients/login', ['email' => 'ASHA@ra.test', 'password' => 'Longpass1'])
        ->assertOk()->assertJsonPath('message', 'Login successful')
        ->assertCookie('website_client_access_token')->assertCookie('website_client_refresh_token');

    expect(ClientSession::query()->count())->toBe(1);
});

it('refuses inactive clients and clients of a suspended vendor', function () {
    client(['email' => 'off@ra.test', 'is_active' => 0]);
    $this->postJson('/api/v1/public/website-clients/login', ['email' => 'off@ra.test', 'password' => 'Pass#12a'])
        ->assertStatus(403)->assertJsonPath('message', 'Your account is not active. Please contact us.');

    $owner = vendorOwner(['status' => 'inactive']);
    client(['email' => 'v@ra.test', 'vendor_id' => $owner->vendor_id]);
    $this->postJson('/api/v1/public/website-clients/login', ['email' => 'v@ra.test', 'password' => 'Pass#12a', 'vendor_id' => $owner->vendor_id])
        ->assertStatus(403)->assertJsonPath('message', 'Vendor account is suspended. Please contact support.');
});

it('signs in with a phone code and hands the app its tokens', function () {
    client(['mobile' => '9876543210']);

    $this->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => '12'])
        ->assertStatus(400)->assertJsonPath('message', 'Please enter a valid mobile number.');
    $this->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => '9000000000'])
        ->assertStatus(401)->assertJsonPath('message', 'No account is registered with that mobile number.');
    $this->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => '9876543210', 'login_as' => 'organizer'])
        ->assertStatus(403)->assertJsonPath('message', 'This mobile number is not registered as an event organizer.');

    // No SMS provider locally: the code comes back (never in production).
    $code = $this->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => '+91 98765 43210'])
        ->assertOk()->assertJsonPath('data.delivered', false)->assertJsonPath('data.expires_in', 600)->json('data.dev_code');

    $wrong = $code === '000000' ? '111111' : '000000';
    $this->postJson('/api/v1/public/website-clients/login/otp/verify', ['mobile' => '9876543210', 'otp' => $wrong])
        ->assertStatus(400)->assertJsonPath('message', 'That code is not correct.');

    $tokens = $this->postJson('/api/v1/public/website-clients/login/otp/verify', ['mobile' => '919876543210', 'otp' => $code])
        ->assertOk()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.expires_in', 900)->json('data');

    $this->withToken($tokens['access_token'])->getJson('/api/v1/client/me')->assertOk();
    $this->postJson('/api/v1/public/website-clients/login/otp/verify', ['mobile' => '9876543210', 'otp' => $code])
        ->assertStatus(400)->assertJsonPath('message', 'Please request a code first.');
});

it('rotates refresh tokens once and ends the session on logout', function () {
    $c = client(['mobile' => '9876543210']);
    $code = $this->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => '9876543210'])->json('data.dev_code');
    $first = $this->postJson('/api/v1/public/website-clients/login/otp/verify', ['mobile' => '9876543210', 'otp' => $code])->json('data');

    $second = $this->postJson('/api/v1/public/website-clients/token/refresh', ['refresh_token' => $first['refresh_token']])
        ->assertOk()->assertJsonPath('message', 'Session refreshed')->json('data');
    $this->postJson('/api/v1/public/website-clients/token/refresh', ['refresh_token' => $first['refresh_token']])
        ->assertStatus(401)->assertJsonPath('message', 'Your session has expired. Please sign in again.');

    // Same session row; the old access token died with the rotation.
    expect(ClientSession::query()->where('client_id', $c->id)->count())->toBe(1);
    $this->withToken($first['access_token'])->getJson('/api/v1/client/me')->assertStatus(401);
    $this->withToken($second['access_token'])->getJson('/api/v1/client/me')->assertOk();

    $this->postJson('/api/v1/public/website-clients/logout', ['refresh_token' => $second['refresh_token']])->assertOk();
    $this->withToken($second['access_token'])->getJson('/api/v1/client/me')
        ->assertStatus(401)->assertJsonPath('message', 'Client authentication required.');
    expect(ClientSession::query()->first()->revoked_reason)->toBe('logout');
});

it('asks for the authenticator code when 2FA is on, and can trust the browser', function () {
    $c = client(['email' => 'tfa@ra.test']);
    $token = accessToken($c);
    $totp = app(Google2FA::class);

    $secret = $this->withToken($token)->postJson('/api/v1/client/security/2fa/setup')->assertOk()->json('data.secret');
    $codes = $this->withToken($token)->postJson('/api/v1/client/security/2fa/confirm', ['code' => $totp->getCurrentOtp($secret)])
        ->assertOk()->assertJsonCount(10, 'data.codes')->json('data.codes');

    $challenge = $this->postJson('/api/v1/public/website-clients/login', ['email' => 'tfa@ra.test', 'password' => 'Pass#12a'])
        ->assertOk()->assertJsonPath('data.requires_2fa', true)->assertCookieMissing('website_client_access_token')->json('data.challenge_token');

    $this->postJson('/api/v1/public/website-clients/login/2fa/verify', ['challenge_token' => $challenge, 'code' => '123'])
        ->assertStatus(401)->assertJsonPath('message', 'That code is not right. Check your authenticator app and try again.');

    // A backup code works once.
    $response = $this->postJson('/api/v1/public/website-clients/login/2fa/verify', ['challenge_token' => $challenge, 'code' => strtolower($codes[0]), 'trust_device' => true])
        ->assertOk()->assertCookie('website_client_access_token')->assertCookie('website_client_device_trust');
    $trust = $response->getCookie('website_client_device_trust', false)->getValue();

    $this->withCredentials()->withUnencryptedCookie('website_client_device_trust', $trust)
        ->postJson('/api/v1/public/website-clients/login', ['email' => 'tfa@ra.test', 'password' => 'Pass#12a'])
        ->assertOk()->assertJsonMissingPath('data.requires_2fa');

    $again = $this->postJson('/api/v1/public/website-clients/login', ['email' => 'tfa@ra.test', 'password' => 'Pass#12a'])->json('data.challenge_token');
    $this->postJson('/api/v1/public/website-clients/login/2fa/verify', ['challenge_token' => $again, 'code' => $codes[0]])->assertStatus(401);
});

it('signs in with Google and returns to an allowed site only', function () {
    config([
        'services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret', 'services.facebook.client_id' => null,
        'clients.oauth_callback_base_url' => 'https://api.ra.test',
        'cors.frontend_origins' => ['https://portal.ra.test'],
    ]);

    $this->getJson('/api/v1/public/website-clients/oauth/providers')->assertOk()->assertJsonPath('data.providers', ['google']);
    $this->getJson('/api/v1/public/website-clients/oauth/google/start?return_to=https://evil.test/x')
        ->assertStatus(400)->assertJsonPath('message', 'That return address is not an allowed site.');

    $location = $this->get('/api/v1/public/website-clients/oauth/google/start?return_to=https://portal.ra.test/login')
        ->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://accounts.google.com/');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $social = (new SocialUser)->setRaw(['email_verified' => true])->map(['id' => 'g-1', 'name' => 'Gita', 'email' => 'gita@ra.test', 'avatar' => null]);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless', 'redirectUrl', 'scopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($social);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $back = $this->get('/api/v1/public/website-clients/oauth/google/callback?code=abc&state='.$query['state'])
        ->assertRedirect()->assertCookie('website_client_access_token')->headers->get('Location');
    expect($back)->toContain('https://portal.ra.test/login?auth=success')->toContain('mode=signup')->toContain('needs_mobile=1');

    $client = Client::query()->withoutGlobalScopes()->where('email', 'gita@ra.test')->firstOrFail();
    expect($client->source->value)->toBe('google')->and($client->provider_id)->toBe('g-1');

    // The state is single use.
    $this->get('/api/v1/public/website-clients/oauth/google/callback?code=abc&state='.$query['state'])->assertStatus(400);

    // The mobile number is attached with a code.
    parse_str((string) parse_url($back, PHP_URL_QUERY), $params);
    $code = $this->postJson('/api/v1/public/website-clients/mobile/send-otp', ['token' => $params['link_token'], 'mobile' => '9123456780'])->json('data.dev_code');
    $this->postJson('/api/v1/public/website-clients/mobile/verify', ['token' => $params['link_token'], 'mobile' => '9123456780', 'otp' => $code])
        ->assertOk()->assertJsonPath('data.client.mobile', '9123456780')->assertJsonPath('data.client.mobile_verified', 1);
});

it('never keeps a token when the client is switched off', function () {
    $c = client();
    $token = accessToken($c);
    $this->withToken($token)->getJson('/api/v1/client/me')->assertOk();

    Client::query()->withoutGlobalScopes()->whereKey($c->id)->update(['is_active' => 0]);
    $this->withToken($token)->getJson('/api/v1/client/me')
        ->assertStatus(403)->assertJsonPath('message', 'Your account is not active. Please contact us.');
});
