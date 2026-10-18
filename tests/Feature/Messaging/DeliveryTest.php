<?php

declare(strict_types=1);

use App\Modules\Clients\Models\ClientDevice;
use App\Modules\Clients\Models\ClientNotificationPref;
use App\Modules\Messaging\Models\EmailConfig;
use App\Modules\Messaging\Models\PushConfig;
use App\Modules\Messaging\Support\PushSender;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
| How messages leave the platform — Settings → Email (/email-configs), Settings
| → Push Notifications (/push-notification-configs), the configured mailer and
| the FCM sender — v1 emailConfig, emailSender, pushNotificationConfig and
| pushSender services.
*/

beforeEach(function () {
    seedHouse();
    $this->admin = accessToken(adminUser());
});

function serviceAccount(string $project = 'event-invit'): string
{
    return (string) json_encode([
        'type' => 'service_account', 'project_id' => $project, 'client_email' => "push@{$project}.iam.gserviceaccount.com",
        'private_key' => file_get_contents(base_path('tests/Fixtures/firebase-test-key.pem')),
    ]);
}

it('keeps e-mail provider secrets encrypted and out of every response', function () {
    $form = ['name' => 'Brevo', 'from_email' => 'hello@ra.test', 'from_name' => 'RA Events', 'driver' => 'brevo',
        'username' => 'login@ra.test', 'password' => 'smtp-pass', 'api_key' => 'xkeysib-1', 'is_default' => true];

    $created = $this->withToken($this->admin)->postJson('/api/v1/email-configs', $form)
        ->assertCreated()->assertJsonPath('message', 'Email configuration created successfully')
        ->assertJsonPath('data.emailConfig.has_api_key', true)->assertJsonPath('data.emailConfig.is_default', true)
        ->assertJsonMissingPath('data.emailConfig.password')->assertJsonMissingPath('data.emailConfig.api_key')
        ->json('data.emailConfig');

    $raw = DB::table('email_configs')->find($created['id']);
    expect($raw->password)->not->toBe('smtp-pass')->and(EmailConfig::query()->find($created['id'])?->password)->toBe('smtp-pass');

    $this->withToken($this->admin)->postJson('/api/v1/email-configs', $form)
        ->assertStatus(400)->assertJsonPath('message', 'An email config with the name "Brevo" already exists.');
    $this->withToken($this->admin)->postJson('/api/v1/email-configs', [...$form, 'name' => 'Bad', 'from_email' => 'nope'])
        ->assertStatus(400)->assertJsonPath('message', 'A valid from email is required');

    // A second default takes over; a blank password on edit keeps the stored one.
    $second = $this->withToken($this->admin)->postJson('/api/v1/email-configs', [...$form, 'name' => 'SMTP', 'driver' => 'smtp', 'host' => 'smtp.ra.test', 'port' => '587'])
        ->assertCreated()->json('data.emailConfig.id');
    expect(EmailConfig::query()->find($created['id'])?->is_default)->toBeFalse();
    $this->withToken($this->admin)->putJson("/api/v1/email-configs/{$second}", ['password' => '', 'from_name' => 'RA'])
        ->assertOk()->assertJsonPath('data.emailConfig.from_name', 'RA');
    expect(EmailConfig::query()->find($second)?->password)->toBe('smtp-pass');

    $this->withToken($this->admin)->getJson('/api/v1/email-configs')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.password');
    $this->withToken($this->admin)->patchJson("/api/v1/email-configs/{$second}/toggle")
        ->assertOk()->assertJsonPath('data.emailConfig.is_active', 0);
    $this->withToken($this->admin)->deleteJson("/api/v1/email-configs/{$created['id']}")->assertOk();

    // The deleted name is free again.
    $this->withToken($this->admin)->postJson('/api/v1/email-configs', $form)->assertCreated();
});

it('sends e-mail through the default provider, and only logs it when there is none', function () {
    $client = client();
    $this->withToken(accessToken($client))->getJson('/api/v1/client/settings')
        ->assertOk()->assertJsonPath('data.delivery.email.enabled', false);

    // No provider: the e-mail is logged and nothing breaks.
    Mail::mailer('configured')->raw('hello', static fn ($m) => $m->to('a@ra.test')->subject('Hi'));

    // The default provider is the one used (a driver v1 never supported proves it).
    EmailConfig::query()->create(['company_id' => 1, 'name' => 'Elastic', 'from_email' => 'x@ra.test', 'driver' => 'elasticemail', 'is_default' => true]);
    expect(fn () => Mail::mailer('configured')->raw('hello', static fn ($m) => $m->to('a@ra.test')->subject('Hi')))
        ->toThrow(Exception::class, 'Unsupported email driver: elasticemail');

    $this->withToken(accessToken($client))->getJson('/api/v1/client/settings')
        ->assertOk()->assertJsonPath('data.delivery.email.enabled', true);
});

it('reports a failed e-mail test as success false with the reason', function () {
    $id = EmailConfig::query()->create(['company_id' => 1, 'name' => 'Elastic', 'from_email' => 'x@ra.test', 'driver' => 'elasticemail'])->id;

    $this->withToken($this->admin)->postJson("/api/v1/email-configs/{$id}/test", ['test_email' => 'me@ra.test'])
        ->assertOk()->assertJsonPath('data.success', false)->assertJsonPath('data.message', 'Unsupported email driver: elasticemail');

    $this->withToken(accessToken(adminUser(attributes: ['email' => 'viewer@ra.test'], role: null)))
        ->postJson("/api/v1/email-configs/{$id}/test")->assertForbidden();
});

it('stores a Firebase project from its service-account file and checks it with Google', function () {
    // Google accepts the key on upload, then refuses it at the later "Test".
    Http::fake(['oauth2.googleapis.com/*' => Http::sequence()
        ->push(['access_token' => 'ya29.test', 'expires_in' => 3600])
        ->push(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)]);

    $first = $this->withToken($this->admin)->post('/api/v1/push-notification-configs', [
        'name' => 'Event Invit', 'vapid_key' => ' BKey ',
        'file' => UploadedFile::fake()->createWithContent('service-account.json', serviceAccount()),
    ], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.pushNotificationConfig.project_id', 'event-invit')
        ->assertJsonPath('data.pushNotificationConfig.is_active', true)
        ->assertJsonPath('data.pushNotificationConfig.connection_status', 'connected')
        ->assertJsonPath('data.pushNotificationConfig.vapid_key', 'BKey')
        ->assertJsonPath('data.pushNotificationConfig.has_private_key', true)
        ->assertJsonMissingPath('data.pushNotificationConfig.private_key')
        ->assertJsonMissingPath('data.pushNotificationConfig.service_account_json')
        ->json('data.pushNotificationConfig.id');
    expect(DB::table('push_notification_configs')->value('private_key'))->not->toContain('PRIVATE KEY');
    Http::assertSent(static fn (HttpRequest $r): bool => $r->url() === 'https://oauth2.googleapis.com/token' && count(explode('.', (string) $r['assertion'])) === 3);

    $this->withToken($this->admin)->postJson('/api/v1/push-notification-configs', ['name' => 'Bad', 'service_account_json' => '{"type":"user"}'])
        ->assertStatus(400)->assertJsonPath('message', 'Invalid Service Account JSON: JSON is not a Google service_account credential file.');

    // Activating another project switches the first off.
    $second = $this->withToken($this->admin)->postJson('/api/v1/push-notification-configs', ['name' => 'Staging'])
        ->assertCreated()->assertJsonPath('data.pushNotificationConfig.is_active', false)->json('data.pushNotificationConfig.id');
    $this->withToken($this->admin)->patchJson("/api/v1/push-notification-configs/{$second}/active")->assertOk();
    expect(PushConfig::query()->find($first)?->is_active)->toBeFalse();
    $this->withToken($this->admin)->getJson('/api/v1/push-notification-configs/active')
        ->assertOk()->assertJsonPath('data.pushNotificationConfig.id', $second);

    $this->withToken($this->admin)->postJson("/api/v1/push-notification-configs/{$second}/test")
        ->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('data.status', 'disconnected');

    $this->withToken($this->admin)->postJson("/api/v1/push-notification-configs/{$first}/test")
        ->assertOk()->assertJsonPath('data.connected', false)->assertJsonPath('data.message', 'Invalid JWT Signature.');

    $this->withToken($this->admin)->getJson('/api/v1/push-notification-configs')->assertOk()->assertJsonCount(2, 'data');
});

it('pushes to every device and switches off tokens Firebase no longer knows', function () {
    $config = PushConfig::query()->create(['company_id' => 1, 'name' => 'App', 'is_active' => true, 'project_id' => 'event-invit',
        'client_email' => 'push@event-invit.iam.gserviceaccount.com', 'private_key' => file_get_contents(base_path('tests/Fixtures/firebase-test-key.pem'))]);
    $client = client();
    $live = ClientDevice::query()->create(['client_id' => $client->id, 'token' => 'live-token', 'platform' => 'android']);
    $gone = ClientDevice::query()->create(['client_id' => $client->id, 'token' => 'gone-token', 'platform' => 'ios']);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => static fn (HttpRequest $r) => $r['message']['token'] === 'live-token'
            ? Http::response(['name' => 'projects/event-invit/messages/1'])
            : Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);

    $results = app(PushSender::class)->send(1, [$live, $gone], ['title' => 'Hello', 'body' => 'See you', 'deep_link' => '/events/1', 'options' => ['priority' => 'normal']]);

    expect(collect($results)->pluck('ok', 'token')->all())->toBe(['live-token' => true, 'gone-token' => false])
        ->and($gone->fresh()?->is_active)->toBeFalse()->and($gone->fresh()?->disabled_reason)->toBe('unregistered')
        ->and($live->fresh()?->is_active)->toBeTrue();
    Http::assertSent(static fn (HttpRequest $r): bool => str_contains($r->url(), "projects/{$config->project_id}/messages:send")
        && $r->hasHeader('Authorization', 'Bearer ya29.test')
        && $r['message']['android']['priority'] === 'NORMAL' && $r['message']['data']['deep_link'] === '/events/1');

    // No active project: nothing is sent, each device says why.
    $config->forceFill(['is_active' => false])->save();
    expect(app(PushSender::class)->send(1, [$live], ['title' => 'Hi'])[0]['reason'])->toStartWith('No Firebase project is active yet.');
});

it('respects a client who switched a notification type off', function () {
    $client = client();
    ClientNotificationPref::query()->create(['client_id' => $client->id, 'channel' => 'in_app', 'type' => 'guest_added', 'enabled' => false]);

    $this->withToken(accessToken($client))->postJson('/api/v1/client/guests', ['first_name' => 'Asha', 'mobile' => '9811111111'])->assertCreated();

    $this->withToken(accessToken($client))->getJson('/api/v1/client/notifications/count')
        ->assertOk()->assertJsonPath('data.unread', 0);
});
