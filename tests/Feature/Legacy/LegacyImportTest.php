<?php

declare(strict_types=1);

use App\Core\Support\EventQr;
use App\Core\Tenancy\Tenancy;
use App\Modules\Sites\Models\Site;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| legacy:import against the REAL old database (the `legacy` connection, local
| copy). Skipped when it is not reachable, e.g. in CI.
*/

beforeEach(function () {
    try {
        DB::connection('legacy')->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('legacy database not reachable');
    }
    seedHouse();
    // The real run stores pasted pictures as files: keep them out of public/uploads.
    config(['media.local_root' => storage_path('framework/testing/legacy-uploads')]);
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/legacy-uploads'));
});

it('rolls a dry run back, then imports, keeping the ids that matter, and imports the same way twice', function () {
    $legacy = DB::connection('legacy');

    $this->artisan('legacy:import', ['--dry-run' => true, '--skip-locations' => true])->assertSuccessful();
    expect(DB::table('clients')->count())->toBe(0)->and(DB::table('events')->count())->toBe(0);

    $this->artisan('legacy:import', ['--skip-locations' => true])->assertSuccessful();

    // Website clients and events keep their ids; every printed QR code still opens its event.
    $kept = DB::table('events')->pluck('qr_token', 'id');
    expect($kept)->not->toBeEmpty();
    $qr = app(EventQr::class);
    foreach ($kept as $id => $token) {
        expect($token)->toBe($legacy->table('events')->where('id', $id)->value('qr_token'));
        if ($token !== null) {
            expect((int) $qr->decrypt($token)['eid'])->toBe((int) $id);
        }
    }
    $websiteClient = $legacy->table('website_clients')->orderBy('id')->first();
    expect(DB::table('clients')->where('id', $websiteClient->id)->value('email'))->toBe(mb_strtolower((string) $websiteClient->email));

    // Vendor clients follow the last website client, recorded in the id map.
    $offset = (int) $legacy->table('website_clients')->max('id');
    $vendorClient = $legacy->table('vendor_clients')->orderBy('id')->first();
    expect(DB::table('clients')->where('id', $offset + $vendorClient->id)->value('source'))->toBe('vendor')
        ->and(DB::table('legacy_id_map')->where('entity', 'vendor_client')->where('old_id', $vendorClient->id)->value('new_id'))->toBe($offset + $vendorClient->id);

    // Owners sign in with their old password: the bcryptjs hash is carried over unchanged.
    $vendor = $legacy->table('vendors')->orderBy('id')->first();
    expect(DB::table('vendor_users')->where('id', $vendor->id)->where('is_owner', 1)->value('password'))->toBe($vendor->password);

    // One marketing site, the house vendor's, with its translations and a rebuilt key registry.
    $marketing = Tenancy::bypass(fn () => Site::query()->where('kind', 'marketing')->sole());
    expect($marketing->vendor_id)->toBe(Vendor::houseId())
        ->and(DB::table('site_translations')->where('site_id', $marketing->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('site_translation_keys')->where('site_id', $marketing->id)->where('section', 'ui-chrome')->count())->toBe(229);

    // Running it again changes nothing.
    $before = collect(['clients', 'vendor_users', 'events', 'participants', 'sites', 'site_settings', 'site_translations', 'event_broadcasts', 'mails'])
        ->mapWithKeys(static fn (string $t): array => [$t => DB::table($t)->count()])->all();
    $this->artisan('legacy:import', ['--skip-locations' => true])->assertSuccessful();
    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, "{$table} changed on the second run");
    }
});
