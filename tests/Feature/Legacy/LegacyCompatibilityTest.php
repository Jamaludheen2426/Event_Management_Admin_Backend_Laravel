<?php

declare(strict_types=1);

use App\Core\Support\EventQr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
| Checks against the REAL old database (the `legacy` connection, local copy).
| Skipped when it is not reachable, e.g. in CI.
*/

function legacyAvailable(): bool
{
    try {
        DB::connection('legacy')->getPdo();

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('decrypts every QR code stored on the old events table to its own event id', function () {
    if (! legacyAvailable()) {
        $this->markTestSkipped('legacy database not reachable');
    }

    $rows = DB::connection('legacy')->table('events')->whereNotNull('qr_token')->get(['id', 'qr_token']);
    $qr = app(EventQr::class);

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        $payload = $qr->decrypt($row->qr_token);
        expect($payload)->not->toBeNull("event {$row->id}: code did not decrypt")
            ->and((int) $payload['eid'])->toBe((int) $row->id);
    }
});

it('verifies a bcryptjs hash produced by the Node API', function () {
    // bcryptjs (Node) writes $2a$ hashes; Laravel's algorithm check is off for them.
    $nodeHash = '$2a$10$yblAcrJ7g7eVtZ2GtWfcw.73XrpHih3bdjnlalNq8ly/8Qd13SUle';

    expect(Hash::check('Secret#1', $nodeHash))->toBeTrue()
        ->and(Hash::check('wrong', $nodeHash))->toBeFalse()
        ->and(Hash::needsRehash($nodeHash))->toBeTrue();
});
