<?php

declare(strict_types=1);

use App\Core\Contracts\MachineTranslator;
use App\Core\Contracts\ObjectStorage;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Models\MissingTranslationKey;
use App\Modules\Platform\Models\TranslationKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeTranslator;

/*
| Media uploads on the local driver, and the admin UI translations.
*/

beforeEach(function () {
    seedHouse();
    $this->uploads = storage_path('framework/testing/uploads-'.uniqid());
    config(['media.local_root' => $this->uploads, 'media.local_url' => 'http://api.test/uploads']);
});

afterEach(function () {
    File::deleteDirectory($this->uploads);
});

it('uploads a file under a unique name and lists it in the media library', function () {
    $token = accessToken(adminUser());

    $file = $this->withToken($token)->post('/api/v1/media/upload', [
        'file' => UploadedFile::fake()->image('My Photo.png', 20, 20), 'folder' => 'gallery',
    ], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('message', 'File uploaded successfully')
        ->json('data.file');

    expect($file['path'])->toStartWith('gallery/my-photo-')->toEndWith('.png')
        ->and($file['url'])->toBe('http://api.test/uploads/'.$file['path'])
        ->and($file['driver'])->toBe('local')
        ->and(File::exists($this->uploads.'/'.$file['path']))->toBeTrue();

    $this->withToken($token)->getJson('/api/v1/media/files?folder=gallery')
        ->assertOk()->assertJsonPath('data.files.0.path', $file['path'])->assertJsonPath('data.files.0.mimetype', 'image/png');
});

it('refuses files over 10 MB, unknown types and paths that climb out of the uploads folder', function () {
    $token = accessToken(adminUser());

    $this->withToken($token)->post('/api/v1/media/upload', ['file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertStatus(400)->assertJsonPath('message', 'File size exceeds the 10MB limit.');
    $this->withToken($token)->post('/api/v1/media/upload', ['file' => UploadedFile::fake()->create('x.exe', 1, 'application/x-msdownload')], ['Accept' => 'application/json'])
        ->assertStatus(400);
    $this->withToken($token)->deleteJson('/api/v1/media', ['path' => '../.env'])
        ->assertStatus(400)->assertJsonPath('message', 'That file path is not allowed.');
    $this->withToken($token)->postJson('/api/v1/media/copy', ['path' => 'a.png', 'target_folder' => '../../', 'new_name' => 'b.png'])
        ->assertStatus(400);
});

it('stores data-URI images for profile avatars and removes the previous one', function () {
    $user = adminUser();
    $png = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('a.png', 4, 4)->getContent());

    $first = $this->withToken(accessToken($user))->putJson('/api/v1/auth/update-profile', ['avatar' => $png])->json('data.user.avatar');
    $second = $this->withToken(accessToken($user))->putJson('/api/v1/auth/update-profile', ['avatar' => $png])->json('data.user.avatar');

    $storage = app(ObjectStorage::class);
    expect($first)->toStartWith('http://api.test/uploads/avatars/avatar-')
        ->and($second)->not->toBe($first)
        ->and(File::exists($this->uploads.'/'.substr($first, strlen('http://api.test/uploads/'))))->toBeFalse()
        ->and(File::exists($this->uploads.'/'.substr($second, strlen('http://api.test/uploads/'))))->toBeTrue()
        ->and($storage->storeDataUri('https://cdn.example.com/x.png', 'avatars'))->toBe('https://cdn.example.com/x.png');
});

it('inlines our own uploads for canvas export, and nothing else', function () {
    $token = accessToken(adminUser());
    $url = app(ObjectStorage::class)->put('<svg/>', 'image/svg+xml', 'shape.svg', 'decorations')['url'];

    $this->withToken($token)->getJson('/api/v1/media/proxy?url='.urlencode($url))
        ->assertOk()->assertJsonPath('data.contentType', 'image/svg+xml')
        ->assertJsonPath('data.dataUri', 'data:image/svg+xml;base64,'.base64_encode('<svg/>'));

    $this->withToken($token)->getJson('/api/v1/media/proxy?url='.urlencode('http://169.254.169.254/latest/meta-data'))
        ->assertStatus(400)->assertJsonPath('message', 'That file is not one of our uploads.');
    $this->withToken($token)->getJson('/api/v1/media/proxy?url='.urlencode('https://evil.example.com/a.png'))
        ->assertStatus(400);
});

it('creates a UI key, machine-translates it into every language and serves the map without login', function () {
    app()->instance(MachineTranslator::class, new FakeTranslator);
    Language::query()->create(['name' => 'English', 'code' => 'en', 'is_default' => true]);
    Language::query()->create(['name' => 'Tamil', 'code' => 'ta']);
    TranslationKey::query()->create(['key' => 'common.cancel', 'default_value' => 'Cancel']);

    $this->withToken(accessToken(adminUser()))->postJson('/api/v1/translation-keys', ['key' => 'common.save', 'defaultValue' => 'Save'])
        ->assertCreated()
        ->assertJsonPath('data.key.group', 'common')
        ->assertJsonCount(2, 'data.key.translations');

    $this->getJson('/api/v1/translations/ta')
        ->assertOk()
        ->assertJsonPath('data.translations', ['common.cancel' => 'Cancel', 'common.save' => '[ta] Save']);
    $this->getJson('/api/v1/translations/stats')->assertJsonPath('data.stats.total_keys', 2);
});

it('collects missing keys from the frontends and turns them into real keys', function () {
    app()->instance(MachineTranslator::class, new FakeTranslator);
    Language::query()->create(['name' => 'Tamil', 'code' => 'ta']);

    $this->postJson('/api/v1/translations/report-missing', ['key' => 'menu.events', 'default_value' => 'Events'])
        ->assertJsonPath('data.status', 'created');
    $this->postJson('/api/v1/translations/report-missing', ['key' => 'menu.events'])->assertJsonPath('data.status', 'updated');
    $this->getJson('/api/v1/translations/missing/count')->assertJsonPath('data.count', 1);

    $token = accessToken(adminUser());
    $this->withToken($token)->getJson('/api/v1/translations/missing')
        ->assertJsonPath('data.0.report_count', 2)->assertJsonPath('data.0.is_active', 1);

    $id = MissingTranslationKey::query()->value('id');
    $this->withToken($token)->postJson("/api/v1/translations/missing/{$id}/create")
        ->assertCreated()->assertJsonPath('data.key.key', 'menu.events')->assertJsonPath('data.key.group', 'menu');

    $this->getJson('/api/v1/translations/missing/count')->assertJsonPath('data.count', 0);
    $this->postJson('/api/v1/translations/report-missing', ['key' => 'menu.events'])->assertJsonPath('data.status', 'exists');
});

it('stops a bulk translation when the daily quota is spent and reports the failures', function () {
    app()->instance(MachineTranslator::class, $fake = new FakeTranslator(quotaSpent: true));
    $ta = Language::query()->create(['name' => 'Tamil', 'code' => 'ta']);
    foreach (range(1, 8) as $n) {
        TranslationKey::query()->create(['key' => "k.{$n}", 'default_value' => "Text {$n}"]);
    }

    $this->withToken(accessToken(adminUser()))->postJson('/api/v1/translations/translate-all', ['language_id' => $ta->id])
        ->assertOk()
        ->assertJsonPath('data.count', 0)
        ->assertJsonPath('data.failed', 8)
        ->assertJsonPath('message', '0 translations created, 8 failed');

    expect($fake->calls)->toBe(5); // circuit breaker
});
