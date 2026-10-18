<?php

declare(strict_types=1);

use App\Core\Support\EventQr;

/*
| Fixtures produced by the Node API's own src/utils/eventQr.js with the
| throwaway secret "test-secret-not-real" (key = scryptSync(secret, 'event-qr-v1', 32)).
| If PHP decrypts them, printed invitations made by Node keep working.
*/
const NODE_KEY = 'qkfh45wpiSiYvq3NHlZjzClQXIWAs0KgxnYEshdhzG8=';
const NODE_TOKEN = 'EVQ1.Wlem7zcJTvzcq916.hIhwHUn2HQsO_F-i4An41Q.lj73iHSdNqkauOrwlvFKDG5sqWaKr_6ShlUsBhXPhfM';
const NODE_SNAPSHOT_TOKEN = 'EVQ1.1Pxbq6xVsH-ySDGF.UQHPVh7jtXaUfDzSG8h-XA.JlXbztaGLLj_MKweMVJFT_Ps8GW9sA9cz4QmRR8Sl7_qNxQeMKAAd3kGN87XRWo6cWz_ZyMDg_twmHWXYeI53-XlHl96VGInXCLJEWPS3p4EtwdvxqFN33ZHMZGGZZx1DkFsKU_wfaD1R72QgG649N4mgsggq7bwhu6PIKB7aAVCBkfgHLAK7MW_taVd9eN82dYzvXIWrgSK1XE';

function nodeQr(): EventQr
{
    return new EventQr(base64_decode(NODE_KEY, true));
}

it('decrypts a code issued by the Node API', function () {
    expect(nodeQr()->decrypt(NODE_TOKEN))->toBe(['v' => 1, 'eid' => 42, 'ts' => 1760000000]);
});

it('decrypts an older snapshot-style Node code and expands it', function () {
    $payload = nodeQr()->decrypt(NODE_SNAPSHOT_TOKEN);

    expect(EventQr::expand($payload))->toMatchArray([
        'version' => 1,
        'event_id' => 7,
        'company_id' => 1,
        'website_client_id' => 3,
        'name' => 'Wedding',
        'start_date' => '2026-12-01',
        'issued_at' => '2025-06-15T15:06:40.000Z',
    ]);
});

it('round-trips a freshly issued code', function () {
    $qr = nodeQr();
    $token = $qr->issue(99, new DateTimeImmutable('@1760000000'));

    expect($token)->toStartWith('EVQ1.')
        ->and($qr->decrypt($token))->toBe(['v' => 1, 'eid' => 99, 'ts' => 1760000000]);
});

it('rejects a tampered code instead of returning another event', function () {
    $parts = explode('.', NODE_TOKEN);
    $parts[3] = strrev($parts[3]);

    expect(nodeQr()->decrypt(implode('.', $parts)))->toBeNull();
});

it('rejects codes that are not ours', function (string $token) {
    expect(nodeQr()->decrypt($token))->toBeNull();
})->with([
    'not a code' => 'hello',
    'unknown version' => str_replace('EVQ1.', 'EVQ9.', NODE_TOKEN),
    'missing part' => 'EVQ1.abc.def',
    'bad base64' => 'EVQ1.***.***.***',
]);

it('refuses a key that is not 32 bytes', function () {
    new EventQr('short');
})->throws(InvalidArgumentException::class);
