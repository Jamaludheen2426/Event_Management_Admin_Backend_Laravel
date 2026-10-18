<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientBackupCode;
use App\Modules\Clients\Models\ClientTwoFactor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * Authenticator-app two-factor sign-in for clients (v1 clientTwoFactor.service).
 *
 * TOTP: 6 digits, 30 seconds, SHA1 — what authenticator apps implement — and
 * one step of clock drift either way. A code is accepted once (the last used
 * time step is stored). Ten one-time backup codes, stored hashed.
 *
 * "Trust this device" is a cookie holding (client id, 2FA confirmed-at),
 * encrypted. Turning 2FA off and on again changes confirmed-at, so every old
 * trust cookie stops working.
 */
final class ClientTwoFactorAuth
{
    public const TRUST_COOKIE = 'website_client_device_trust';

    private const BACKUP_CODE_COUNT = 10;

    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(private readonly Google2FA $totp) {}

    /** @return array<string, mixed> */
    public function status(Client $client): array
    {
        $record = ClientTwoFactor::query()->where('client_id', $client->id)->first();
        $unused = ClientBackupCode::query()->where('client_id', $client->id)->whereNull('used_at')->count();
        $iso = static fn ($date): ?string => $date?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'is_enabled' => (bool) $record?->is_enabled,
            'is_pending' => $record !== null && ! $record->is_enabled,
            'confirmed_at' => $iso($record?->confirmed_at),
            'last_used_at' => $iso($record?->last_used_at),
            'backup_codes_remaining' => $unused,
            'covers' => [
                'web_sign_in' => true,
                'mobile_app' => false,
                'note' => 'The mobile app signs in with your phone number and a one-time code, and is not asked for an authenticator code.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function beginSetup(Client $client): array
    {
        $record = ClientTwoFactor::query()->where('client_id', $client->id)->first();
        if ($record?->is_enabled) {
            throw ApiException::badRequest('Two-factor authentication is already on. Turn it off first if you want to set it up again.');
        }

        $secret = $record->secret ?? $this->totp->generateSecretKey(32);
        ClientTwoFactor::query()->updateOrCreate(['client_id' => $client->id], ['secret' => $secret, 'is_enabled' => false]);
        $issuer = (string) config('services.two_factor.issuer', 'Event Invit');

        return [
            'secret' => $secret,
            'otpauth_url' => $this->totp->getQRCodeUrl($issuer, $client->email ?? 'client-'.$client->id, $secret),
            'issuer' => $issuer,
            'digits' => 6,
            'period' => 30,
        ];
    }

    /** @return array{codes: list<string>} */
    public function confirmSetup(Client $client, mixed $code): array
    {
        $record = ClientTwoFactor::query()->where('client_id', $client->id)->first()
            ?? throw ApiException::badRequest('Start setting up two-factor authentication first.');
        if ($record->is_enabled) {
            throw ApiException::badRequest('Two-factor authentication is already on.');
        }
        if (! $this->verifyTotp($record, $code)) {
            throw ApiException::badRequest('That code is not right. Check your authenticator app and try the current code.');
        }

        return DB::transaction(function () use ($record, $client): array {
            $record->forceFill(['is_enabled' => true, 'confirmed_at' => now()])->save();

            return ['codes' => $this->issueBackupCodes($client)];
        });
    }

    /** @return array{is_enabled: false} */
    public function disable(Client $client, mixed $password, mixed $code): array
    {
        $record = ClientTwoFactor::query()->where('client_id', $client->id)->where('is_enabled', true)->first()
            ?? throw ApiException::badRequest('Two-factor authentication is not on.');

        $proved = is_scalar($code) && (string) $code !== ''
            && ($this->verifyTotp($record, $code) || $this->consumeBackupCode($client->id, $code));
        if (! $proved && is_scalar($password) && (string) $password !== '' && $client->hasPassword()) {
            $proved = Hash::check((string) $password, (string) $client->password);
        }
        if (! $proved) {
            throw ApiException::badRequest('Enter your current password or a code from your authenticator app to turn this off.');
        }

        DB::transaction(static function () use ($client, $record): void {
            ClientBackupCode::query()->where('client_id', $client->id)->delete();
            $record->delete();
        });

        return ['is_enabled' => false];
    }

    /** @return array{codes: list<string>} */
    public function regenerateBackupCodes(Client $client): array
    {
        if (! $this->isEnabled($client)) {
            throw ApiException::badRequest('Turn on two-factor authentication before generating backup codes.');
        }

        return ['codes' => $this->issueBackupCodes($client)];
    }

    public function isEnabled(Client $client): bool
    {
        return ClientTwoFactor::query()->where('client_id', $client->id)->where('is_enabled', true)->exists();
    }

    /** A TOTP or an unused backup code, at sign-in. */
    public function verifyForLogin(Client $client, mixed $code): bool
    {
        $record = ClientTwoFactor::query()->where('client_id', $client->id)->where('is_enabled', true)->first();

        return $record !== null && ($this->verifyTotp($record, $code) || $this->consumeBackupCode($client->id, $code));
    }

    /** The encrypted trust-cookie value for this browser, or null when 2FA is off. */
    public function trustCookieValue(Client $client): ?string
    {
        $confirmed = $this->confirmedAt($client);

        return $confirmed !== null ? Crypt::encryptString($client->id.'|'.$confirmed) : null;
    }

    public function isTrustedBrowser(Client $client, ?string $cookie): bool
    {
        if ($cookie === null || $cookie === '') {
            return false;
        }
        try {
            [$id, $confirmed] = explode('|', Crypt::decryptString($cookie)) + [null, null];
        } catch (Throwable) {
            return false;
        }

        return (int) $id === $client->id && $confirmed !== null && $confirmed === $this->confirmedAt($client);
    }

    private function confirmedAt(Client $client): ?string
    {
        $record = ClientTwoFactor::query()->where('client_id', $client->id)->where('is_enabled', true)->first();

        return $record?->confirmed_at?->format('Uv');
    }

    private function verifyTotp(ClientTwoFactor $record, mixed $code): bool
    {
        $token = (string) preg_replace('/\D/', '', is_scalar($code) ? (string) $code : '');
        if (strlen($token) !== 6) {
            return false;
        }

        try {
            $step = $this->totp->verifyKeyNewer($record->secret, $token, $record->last_used_counter, 1);
        } catch (Throwable) {
            return false;
        }
        if ($step === false) {
            return false;
        }

        $step = $step === true ? (int) $this->totp->getTimestamp() : (int) $step;
        $record->forceFill(['last_used_counter' => $step, 'last_used_at' => now()])->save();

        return true;
    }

    /** @return list<string> the plain codes, shown once */
    private function issueBackupCodes(Client $client): array
    {
        ClientBackupCode::query()->where('client_id', $client->id)->delete();

        $codes = [];
        $rows = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; $i++) {
            $code = implode('-', array_map(fn (): string => $this->randomGroup(), [1, 2, 3]));
            $codes[] = $code;
            $rows[] = ['client_id' => $client->id, 'code_hash' => Hash::make($this->normalise($code)), 'created_at' => now(), 'updated_at' => now()];
        }
        ClientBackupCode::query()->insert($rows);

        return $codes;
    }

    private function consumeBackupCode(int $clientId, mixed $input): bool
    {
        $candidate = $this->normalise($input);
        if ($candidate === '') {
            return false;
        }

        foreach (ClientBackupCode::query()->where('client_id', $clientId)->whereNull('used_at')->get() as $row) {
            if (Hash::check($candidate, $row->code_hash)) {
                return ClientBackupCode::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) === 1;
            }
        }

        return false;
    }

    private function normalise(mixed $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(is_scalar($value) ? (string) $value : ''));
    }

    private function randomGroup(): string
    {
        $group = '';
        for ($i = 0; $i < 4; $i++) {
            $group .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $group;
    }
}
