<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientDevice;

/**
 * The app's push tokens (FCM), v1 clientDevice.controller.
 *
 * A token follows the app install, not the account: when someone else signs in
 * on the same phone the token moves to them, so the previous owner stops
 * getting notifications on a phone they no longer use.
 */
final class ClientDevices
{
    /** @param array<string, mixed> $input token, platform, device_name, app_version */
    public function register(Client $client, array $input): void
    {
        $token = trim(is_string($input['token'] ?? null) ? $input['token'] : '');
        if ($token === '') {
            throw ApiException::badRequest('A device token is required.');
        }
        if (mb_strlen($token) > 255) {
            throw ApiException::badRequest('That device token is not valid.');
        }
        $platform = in_array($input['platform'] ?? null, ['android', 'ios', 'web'], true) ? $input['platform'] : 'android';
        $name = is_string($input['device_name'] ?? null) && $input['device_name'] !== '' ? mb_substr($input['device_name'], 0, 120) : null;
        $version = is_string($input['app_version'] ?? null) && $input['app_version'] !== '' ? mb_substr($input['app_version'], 0, 40) : null;

        $device = ClientDevice::query()->firstOrNew(['token' => $token]);
        $device->fill([
            'client_id' => $client->id,
            'platform' => $platform,
            'device_name' => $name ?? $device->device_name,
            'app_version' => $version ?? $device->app_version,
            'is_active' => true,
            'disabled_reason' => null,
            'last_seen_at' => now(),
        ])->save();
    }

    public function release(Client $client, mixed $token): void
    {
        $token = trim(is_string($token) ? $token : '');
        if ($token === '') {
            throw ApiException::badRequest('A device token is required.');
        }

        ClientDevice::query()->where('client_id', $client->id)->where('token', $token)
            ->update(['is_active' => false, 'disabled_reason' => 'signed_out']);
    }

    /** @return list<array<string, mixed>> */
    public function list(Client $client): array
    {
        $iso = static fn ($date): ?string => $date?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return ClientDevice::query()->where('client_id', $client->id)->orderByDesc('last_seen_at')->get()
            ->map(static fn (ClientDevice $d): array => [
                'id' => $d->id,
                'platform' => $d->platform,
                'device_name' => $d->device_name,
                'app_version' => $d->app_version,
                'is_active' => $d->is_active,
                'disabled_reason' => $d->disabled_reason,
                'last_seen_at' => $iso($d->last_seen_at),
                'created_at' => $iso($d->created_at),
            ])->all();
    }
}
