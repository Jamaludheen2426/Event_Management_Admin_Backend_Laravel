<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Core\Tenancy\CompanyScope;
use App\Modules\Clients\Models\ClientDevice;
use App\Modules\Messaging\Models\PushConfig;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Push notifications through FCM v1 (v1 pushSender.service). Tokens Firebase
 * reports as uninstalled or invalid are switched off in one update per reason
 * (production is ~374 ms a query).
 */
final class PushSender
{
    private const BATCH = 25;

    public function __construct(private readonly FirebaseAuth $auth) {}

    /** @return array{enabled: bool, config: PushConfig|null, reason: string|null} */
    public function availability(int $companyId): array
    {
        $config = PushConfig::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('is_active', true)->first();
        if ($config === null) {
            return ['enabled' => false, 'config' => null, 'reason' => 'No Firebase project is active yet. Your notification is saved and recorded, '
                .'but it will not be delivered until an administrator activates a Firebase configuration in Settings → Push Notifications.'];
        }
        if (! $config->canSend()) {
            return ['enabled' => false, 'config' => $config, 'reason' => "The active Firebase config \"{$config->name}\" is missing its service account "
                .'credentials, so notifications cannot be delivered yet.'];
        }

        return ['enabled' => true, 'config' => $config, 'reason' => null];
    }

    /**
     * @param  iterable<ClientDevice>  $devices
     * @param  array{title: string, body?: string|null, image_url?: string|null, click_action?: string|null, deep_link?: string|null, data?: array<string, mixed>, options?: array<string, mixed>}  $payload
     * @return list<array{token: string, device_id: int, ok: bool, reason: string|null}>
     */
    public function send(int $companyId, iterable $devices, array $payload): array
    {
        $devices = collect($devices)->values();
        $fail = static fn (string $reason): array => $devices->map(static fn (ClientDevice $d): array => ['token' => $d->token, 'device_id' => $d->id, 'ok' => false, 'reason' => $reason])->all();
        if ($devices->isEmpty()) {
            return [];
        }

        $state = $this->availability($companyId);
        $config = $state['config'];
        if (! $state['enabled'] || $config === null) {
            return $fail((string) $state['reason']);
        }
        try {
            $accessToken = $this->auth->accessToken($config);
        } catch (Throwable $e) {
            report($e);

            return $fail('Firebase authentication failed: '.$e->getMessage());
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$config->project_id}/messages:send";
        $results = [];
        $dead = [];
        foreach ($devices->chunk(self::BATCH) as $batch) {
            $batch = $batch->values();
            $responses = Http::pool(fn (Pool $pool): array => $batch->map(fn (ClientDevice $d) => $pool->withToken($accessToken)->timeout(15)
                ->post($url, ['message' => $this->message($d->token, $payload)]))->all());
            foreach ($batch as $i => $device) {
                $response = $responses[$i] ?? null;
                if ($response instanceof Response && $response->successful()) {
                    $results[] = ['token' => $device->token, 'device_id' => $device->id, 'ok' => true, 'reason' => null];

                    continue;
                }
                $error = $this->classify($response);
                if ($error['dead']) {
                    $dead[$error['reason']][] = $device->id;
                }
                $results[] = ['token' => $device->token, 'device_id' => $device->id, 'ok' => false, 'reason' => $error['message']];
            }
        }

        foreach ($dead as $reason => $ids) {
            ClientDevice::query()->whereKey($ids)->update(['is_active' => false, 'disabled_reason' => $reason]);
        }

        return $results;
    }

    /**
     * The FCM v1 message (v1 buildMessage): notification + data for the app,
     * Android channel and priority, APNs priority, expiry and badge.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function message(string $token, array $payload): array
    {
        $o = (array) ($payload['options'] ?? []);
        $ttl = max(0, min(2_419_200, (int) ($o['ttl_seconds'] ?? 0) ?: 86_400));
        $image = $payload['image_url'] ?? null;
        $high = ($o['priority'] ?? null) !== 'normal';

        $data = [];
        foreach ((array) ($payload['data'] ?? []) as $key => $value) {
            if ($key !== '' && $value !== null) {
                $data[(string) $key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
            }
        }
        $data['click_action'] = (string) (($payload['click_action'] ?? null) ?: 'open_app');
        if (! empty($payload['deep_link'])) {
            $data['deep_link'] = (string) $payload['deep_link'];
        }

        $aps = ['sound' => 'default'];
        if (($o['badge_mode'] ?? null) === 'set' && is_numeric($o['badge_value'] ?? null)) {
            $aps['badge'] = (int) $o['badge_value'];
        }
        if (($o['badge_mode'] ?? null) === 'clear') {
            $aps['badge'] = 0;
        }
        if (! empty($o['content_available'])) {
            $aps['content-available'] = 1;
        }

        return [
            'token' => $token,
            'notification' => array_filter([
                'title' => mb_substr((string) ($payload['title'] ?? ''), 0, 200),
                'body' => mb_substr((string) ($payload['body'] ?? ''), 0, 1000),
                'image' => $image,
            ], static fn ($v): bool => $v !== null),
            'data' => $data,
            'android' => array_filter([
                'priority' => $high ? 'HIGH' : 'NORMAL',
                'ttl' => "{$ttl}s",
                'collapse_key' => isset($o['collapse_key']) ? (string) $o['collapse_key'] : null,
                'restricted_package_name' => isset($o['restricted_package_name']) ? (string) $o['restricted_package_name'] : null,
                'notification' => array_filter(['channel_id' => 'high_importance_channel', 'default_sound' => true, 'image' => $image], static fn ($v): bool => $v !== null),
            ], static fn ($v): bool => $v !== null),
            'apns' => [
                'headers' => array_filter([
                    'apns-priority' => $high ? '10' : '5',
                    'apns-expiration' => (string) (time() + $ttl),
                    'apns-collapse-id' => isset($o['collapse_key']) ? (string) $o['collapse_key'] : null,
                ], static fn ($v): bool => $v !== null),
                'payload' => ['aps' => $aps],
            ],
        ];
    }

    /** @return array{dead: bool, reason: string, message: string} */
    private function classify(mixed $response): array
    {
        if (! $response instanceof Response) {
            return ['dead' => false, 'reason' => 'error', 'message' => $response instanceof Throwable ? $response->getMessage() : 'Delivery failed'];
        }
        $status = $response->status();
        $code = collect((array) $response->json('error.details'))->pluck('errorCode')->filter()->first() ?? $response->json('error.status') ?? '';
        $message = $response->json('error.message');

        return match (true) {
            $code === 'UNREGISTERED' || $status === 404 => ['dead' => true, 'reason' => 'unregistered', 'message' => 'App was uninstalled or token expired'],
            $code === 'INVALID_ARGUMENT' || $status === 400 => ['dead' => true, 'reason' => 'invalid', 'message' => is_string($message) ? $message : 'Token rejected by Firebase'],
            $status === 429 => ['dead' => false, 'reason' => 'rate_limited', 'message' => 'Firebase rate limit reached'],
            default => ['dead' => false, 'reason' => 'error', 'message' => is_string($message) ? $message : 'Delivery failed'],
        };
    }
}
