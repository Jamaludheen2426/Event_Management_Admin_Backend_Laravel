<?php

declare(strict_types=1);

use App\Core\Tenancy\Tenancy;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;

/*
| Settings (per company, secrets encrypted) and plugins.
*/

beforeEach(function () {
    seedHouse();
});

function setting(string $key, ?string $value, string $group = 'general', int $company = 1, string $type = 'text'): Setting
{
    return Tenancy::bypass(fn () => Setting::query()->create(['company_id' => $company, 'key' => $key, 'value' => $value, 'group' => $group, 'type' => $type]));
}

it('serves public settings without login, never the secrets, with company branding', function () {
    setting('admin_title', 'Event Admin');
    setting('primary_color', '#123456', 'theme');
    setting('google_client_secret', 'shh', 'social_login');
    setting('google_redirect_uri', 'https://x/cb', 'social_login');
    setting('menu', '{"a":1}', 'appearance', type: 'json');
    setting('stripe_secret_key', 'sk_live', 'stripe');

    $this->getJson('/api/v1/settings/public')
        ->assertOk()
        ->assertJsonPath('data.settings.admin_title', 'Event Admin')
        ->assertJsonPath('data.settings.primary_color', '#123456')
        ->assertJsonPath('data.settings.menu', ['a' => 1])
        ->assertJsonMissingPath('data.settings.google_client_secret')
        ->assertJsonMissingPath('data.settings.google_redirect_uri')
        ->assertJsonMissingPath('data.settings.stripe_secret_key')
        ->assertJsonPath('data.company.name', config('app.name'));
});

it('stores secret values encrypted and reads them back in plain text', function () {
    $token = accessToken(adminUser());

    $this->withToken($token)->postJson('/api/v1/settings/bulk', ['group' => 'stripe', 'stripe_secret_key' => 'sk_test_123'])
        ->assertOk()->assertJsonPath('data.settings.0.value', 'sk_test_123');

    expect(DB::table('settings')->where('key', 'stripe_secret_key')->value('value'))->not->toBe('sk_test_123');

    $this->withToken($token)->getJson('/api/v1/settings/group/stripe')->assertJsonPath('data.settings.0.value', 'sk_test_123');
});

it('keeps settings per company (v1 let one company overwrite another\'s)', function () {
    $other = Company::factory()->create();
    setting('admin_title', 'Theirs', company: $other->id);

    $this->withToken(accessToken(adminUser()))->postJson('/api/v1/settings/bulk', ['group' => 'general', 'admin_title' => 'Ours'])
        ->assertOk();

    expect(Tenancy::bypass(fn () => Setting::query()->where('key', 'admin_title')->pluck('value', 'company_id')->all()))
        ->toBe([$other->id => 'Theirs', 1 => 'Ours']);
});

it('needs the group permission for a bulk save, besides settings.edit', function () {
    $user = giveRole(adminUser(role: null), customRole(['settings.edit' => false, 'general_settings.edit' => false]));
    $token = accessToken($user);

    $this->withToken($token)->postJson('/api/v1/settings/bulk', ['group' => 'general', 'admin_title' => 'X'])->assertOk();
    $this->withToken($token)->postJson('/api/v1/settings/bulk', ['group' => 'stripe', 'stripe_secret_key' => 'X'])
        ->assertStatus(403)->assertJsonPath('message', 'Access denied. Insufficient permissions.');
});

it('updates one setting by key and 404s an unknown key', function () {
    setting('admin_title', 'Old');
    $token = accessToken(adminUser());

    $this->withToken($token)->putJson('/api/v1/settings/admin_title', ['value' => 'New'])
        ->assertOk()->assertJsonPath('data.setting.value', 'New');
    $this->withToken($token)->putJson('/api/v1/settings/nope', ['value' => 'x'])
        ->assertStatus(404)->assertJsonPath('message', 'Setting not found');
});

it('lists the predefined plugins grouped in v1 category order and toggles one', function () {
    $token = accessToken(adminUser());

    $response = $this->withToken($token)->getJson('/api/v1/plugins')->assertOk();
    expect(array_keys($response->json('data.grouped')))->toBe(['content', 'authentication', 'analytics', 'storage', 'payment', 'maps', 'security', 'communication', 'general'])
        ->and($response->json('data.plugins'))->toHaveCount(20);

    $this->withToken($token)->putJson('/api/v1/plugins/stripe/toggle')
        ->assertOk()->assertJsonPath('message', 'Plugin enabled successfully')->assertJsonPath('data.plugin.is_active', 1);
    $this->withToken($token)->getJson('/api/v1/plugins/unknown')->assertStatus(404);
});

it('answers the admin frontend bootstrap calls without login', function () {
    $this->getJson('/api/v1/setup/status')->assertOk()->assertJsonPath('data.installed', true);
    $this->getJson('/api/v1/timezones')->assertOk()->assertJsonPath('data.timezones.0.value', 'Pacific/Midway');
});
