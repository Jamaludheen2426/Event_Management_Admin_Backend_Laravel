<?php

declare(strict_types=1);

use App\Modules\Clients\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/*
| The client's own account: /api/v1/client/* (v1 clientPortal, clientSecurity,
| clientPreferences, clientDevice controllers).
*/

beforeEach(function () {
    seedHouse();
});

/**
 * Sign in through the real phone-code flow, so the session row exists.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function clientSignIn(Client $client, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'): array
{
    $test = test();
    $code = $test->postJson('/api/v1/public/website-clients/login/otp/request', ['mobile' => $client->mobile])->json('data.dev_code');

    return $test->withHeader('User-Agent', $agent)
        ->postJson('/api/v1/public/website-clients/login/otp/verify', ['mobile' => $client->mobile, 'otp' => $code])->json('data');
}

it('edits the profile and refuses an e-mail or number another client has', function () {
    client(['email' => 'taken@ra.test', 'mobile' => '9111111111']);
    $token = accessToken(client(['email' => 'me@ra.test']));

    $this->withToken($token)->getJson('/api/v1/client/me')
        ->assertOk()->assertJsonPath('message', 'Client profile retrieved')->assertJsonPath('data.client.email', 'me@ra.test');

    $this->withToken($token)->putJson('/api/v1/client/me', ['email' => 'TAKEN@ra.test'])
        ->assertStatus(400)->assertJsonPath('message', 'That email address is already in use.');
    $this->withToken($token)->putJson('/api/v1/client/me', ['mobile' => '+91 91111 11111'])
        ->assertStatus(400)->assertJsonPath('message', 'That mobile number is already in use.');
    $this->withToken($token)->putJson('/api/v1/client/me', ['name' => '  '])
        ->assertStatus(400)->assertJsonPath('message', 'Your name cannot be empty.');

    $this->withToken($token)->putJson('/api/v1/client/me', ['name' => 'New Name', 'bio' => 'Hi', 'email' => 'new@ra.test', 'password' => 'ignored'])
        ->assertOk()->assertJsonPath('data.client.name', 'New Name')->assertJsonPath('data.client.email', 'new@ra.test')
        ->assertJsonPath('data.client.email_verified', 0);

    $this->withToken($token)->putJson('/api/v1/client/favourite-templates', ['template_ids' => ['royal-gold', 'royal-gold', '../x', 7]])
        ->assertOk()->assertJsonPath('data.favourite_templates', ['royal-gold']);
    $this->withToken($token)->getJson('/api/v1/client/me')->assertJsonPath('data.client.favourite_templates', ['royal-gold']);
});

it('changes the password and signs out every other device', function () {
    $c = client(['mobile' => '9222222222']);
    $phone = clientSignIn($c, 'Dart/3.4 (dart:io)');
    $laptop = clientSignIn($c);

    $this->withToken($laptop['access_token'])->putJson('/api/v1/client/me/password', ['current_password' => 'wrong', 'new_password' => 'Another1x'])
        ->assertStatus(401)->assertJsonPath('message', 'Your current password is not correct.');
    $this->withToken($laptop['access_token'])->putJson('/api/v1/client/me/password', ['current_password' => 'Pass#12a', 'new_password' => 'Pass#12a'])
        ->assertStatus(400)->assertJsonPath('message', 'Your new password must be different from the current one.');

    $this->withToken($laptop['access_token'])->putJson('/api/v1/client/me/password', ['current_password' => 'Pass#12a', 'new_password' => 'Another1x'])
        ->assertOk()->assertJsonPath('data.signed_out_sessions', 1)
        ->assertJsonPath('message', 'Password updated. You have been signed out on 1 other device.');

    $this->withToken($phone['access_token'])->getJson('/api/v1/client/me')->assertStatus(401);
    $this->withToken($laptop['access_token'])->getJson('/api/v1/client/me')->assertOk();
});

it('lists sign-ins per device and signs one out', function () {
    $c = client(['mobile' => '9333333333']);
    $phone = clientSignIn($c, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Mobile/15E148 Safari/604.1');
    $laptop = clientSignIn($c);

    $sessions = $this->withToken($laptop['access_token'])->getJson('/api/v1/client/security/sessions')
        ->assertOk()->assertJsonCount(2, 'data.sessions')->assertJsonPath('data.location_available', false)->json('data');
    $current = collect($sessions['sessions'])->firstWhere('is_current', true);
    $other = collect($sessions['sessions'])->firstWhere('is_current', false);
    expect($current['id'])->toBe($sessions['current_session_id'])
        ->and($current['device_name'])->toBe('Windows · Chrome')
        ->and($other['device_name'])->toBe('iOS · Safari')->and($other['device_type'])->toBe('mobile');

    $this->withToken($laptop['access_token'])->deleteJson("/api/v1/client/security/sessions/{$other['id']}")
        ->assertOk()->assertJsonCount(1, 'data.sessions');
    $this->withToken($phone['access_token'])->getJson('/api/v1/client/me')->assertStatus(401);

    // Another client's session is not ours to end.
    $stranger = clientSignIn(client(['mobile' => '9444444444']));
    $theirs = $this->withToken($stranger['access_token'])->getJson('/api/v1/client/security/sessions')->json('data.current_session_id');
    $this->withToken($laptop['access_token'])->deleteJson("/api/v1/client/security/sessions/{$theirs}")
        ->assertStatus(404)->assertJsonPath('message', 'That session was not found.');
});

it('closes the account after the password is confirmed', function () {
    $c = client(['email' => 'bye@ra.test']);
    $token = accessToken($c);

    $this->withToken($token)->deleteJson('/api/v1/client/me', [])
        ->assertStatus(400)->assertJsonPath('message', 'Please enter your password to confirm.');
    $this->withToken($token)->deleteJson('/api/v1/client/me', ['password' => 'nope'])
        ->assertStatus(401)->assertJsonPath('message', 'That password is not correct.');
    $this->withToken($token)->deleteJson('/api/v1/client/me', ['password' => 'Pass#12a'])
        ->assertOk()->assertJsonPath('message', 'Your account has been closed');

    $this->withToken($token)->getJson('/api/v1/client/me')->assertStatus(401);
    // The e-mail is free again (v1 renamed deleted rows to achieve this).
    $this->postJson('/api/v1/public/website-clients/register', ['name' => 'Back', 'email' => 'bye@ra.test', 'password' => 'Longpass1'])->assertCreated();
});

it('saves settings and notification switches against the catalogue', function () {
    $token = accessToken(client());

    $this->withToken($token)->getJson('/api/v1/client/settings')
        ->assertOk()->assertJsonPath('data.preferences.theme', 'light')->assertJsonPath('data.dnd_active', false)
        ->assertJsonPath('data.notifications.email.0.types.0.type', 'new_rsvp')
        ->assertJsonPath('data.notifications.email.3.types.1.enabled', false)
        ->assertJsonPath('data.delivery.email.enabled', false);

    $this->withToken($token)->putJson('/api/v1/client/settings/preferences', ['theme' => 'neon'])
        ->assertStatus(400)->assertJsonPath('message', '"neon" is not an accepted value for theme.');
    $this->withToken($token)->putJson('/api/v1/client/settings/preferences', ['dnd_starts_at' => '2026-10-20T10:00:00Z', 'dnd_ends_at' => '2026-10-20T09:00:00Z'])
        ->assertStatus(400)->assertJsonPath('message', 'Do Not Disturb must end after it starts.');
    $this->withToken($token)->putJson('/api/v1/client/settings/preferences', ['theme' => 'dark', 'items_per_page' => '50', 'compact_mode' => 'true', 'time_zone' => 'Europe/London'])
        ->assertOk()->assertJsonPath('data.preferences.theme', 'dark')->assertJsonPath('data.preferences.items_per_page', 50)
        ->assertJsonPath('data.preferences.compact_mode', true)->assertJsonPath('data.preferences.time_zone', 'Europe/London');

    $this->withToken($token)->putJson('/api/v1/client/settings/notifications', ['items' => [['channel' => 'email', 'type' => 'made_up']]])
        ->assertStatus(400)->assertJsonPath('message', '"made_up" is not a email notification in this system.');
    $this->withToken($token)->putJson('/api/v1/client/settings/notifications', ['items' => [
        ['channel' => 'email', 'type' => 'new_rsvp', 'enabled' => false, 'frequency' => 'daily_digest'],
        ['channel' => 'in_app', 'type' => 'new_guest', 'sound' => true],
    ]])->assertOk()
        ->assertJsonPath('data.notifications.email.0.types.0.enabled', false)
        ->assertJsonPath('data.notifications.email.0.types.0.frequency', 'daily_digest')
        ->assertJsonPath('data.notifications.in_app.1.types.1.sound', true)
        ->assertJsonPath('data.notifications.in_app.1.types.1.is_set', true);
});

it('registers push devices and moves a token to whoever signs in on the phone', function () {
    $first = client();
    $second = client();

    $this->withToken(accessToken($first))->postJson('/api/v1/client/devices', ['token' => 'fcm-1', 'platform' => 'ios', 'device_name' => 'iPhone'])
        ->assertOk()->assertJsonPath('data.registered', true);
    $this->withToken(accessToken($second))->postJson('/api/v1/client/devices', ['token' => 'fcm-1'])->assertOk();

    $this->withToken(accessToken($first))->getJson('/api/v1/client/devices')->assertJsonCount(0, 'data.devices')->assertJsonPath('data.push_enabled', false);
    $this->withToken(accessToken($second))->getJson('/api/v1/client/devices')
        ->assertJsonPath('data.devices.0.device_name', 'iPhone')->assertJsonPath('data.devices.0.platform', 'android')
        ->assertJsonMissingPath('data.devices.0.token');

    $this->withToken(accessToken($second))->deleteJson('/api/v1/client/devices', ['token' => 'fcm-1'])->assertOk();
    $this->withToken(accessToken($second))->getJson('/api/v1/client/devices')->assertJsonPath('data.push_enabled', false);
});

it('uploads and removes the profile photo', function () {
    $uploads = storage_path('framework/testing/uploads-'.uniqid());
    config(['media.local_root' => $uploads, 'media.local_url' => 'http://api.test/uploads']);
    $token = accessToken(client());

    $this->withToken($token)->put('/api/v1/client/me/avatar', ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJsonPath('message', 'Please choose a JPG, PNG, WEBP or GIF image.');
    $url = $this->withToken($token)->put('/api/v1/client/me/avatar', ['file' => UploadedFile::fake()->image('me.png')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('message', 'Photo updated')->json('data.client.avatar_url');
    expect($url)->toBeString()->not->toBe('');

    $this->withToken($token)->deleteJson('/api/v1/client/me/avatar')->assertOk()->assertJsonPath('data.client.avatar_url', null);
    File::deleteDirectory($uploads);
});
