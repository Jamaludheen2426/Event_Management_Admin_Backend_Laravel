<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Modules\Clients\Models\ClientDevice;
use App\Modules\Events\Models\Event;
use App\Modules\Vendors\Models\Vendor;

/**
 * Push to people (app accounts) rather than to tokens: an account counts as
 * reached when any one of its devices accepted the notification.
 */
final class AppPush
{
    public function __construct(private readonly PushSender $sender) {}

    /** The company whose Firebase project an event's app belongs to. */
    public function companyOf(Event $event): int
    {
        return (int) Vendor::query()->withoutGlobalScopes()->whereKey($event->vendor_id)->value('company_id');
    }

    /** @return array{enabled: bool, reason: string|null} */
    public function availability(Event $event): array
    {
        $state = $this->sender->availability($this->companyOf($event));

        return ['enabled' => $state['enabled'], 'reason' => $state['reason']];
    }

    /**
     * Which of these accounts have a live device (one query for everyone:
     * production is ~374 ms a query).
     *
     * @param  list<int>  $clientIds
     * @return array<int, true>
     */
    public function reachable(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        return array_fill_keys(ClientDevice::query()->whereIn('client_id', array_values(array_unique($clientIds)))
            ->where('is_active', true)->distinct()->pluck('client_id')->map(static fn ($id): int => (int) $id)->all(), true);
    }

    /**
     * @param  list<int>  $clientIds
     * @param  array<string, mixed>  $payload  PushSender payload
     * @return array<int, array{ok: bool, reason: string|null}> per account; accounts without a device are absent
     */
    public function send(Event $event, array $clientIds, array $payload): array
    {
        $devices = $clientIds === [] ? collect() : ClientDevice::query()->whereIn('client_id', array_values(array_unique($clientIds)))
            ->where('is_active', true)->get();
        if ($devices->isEmpty()) {
            return [];
        }

        /** @var array{title: string} $payload */
        $results = collect($this->sender->send($this->companyOf($event), $devices, $payload))->keyBy('device_id');
        $byClient = [];
        foreach ($devices as $device) {
            $result = $results->get($device->id);
            $seen = $byClient[$device->client_id] ?? ['ok' => false, 'reason' => null];
            if (($result['ok'] ?? false) === true) {
                $seen['ok'] = true;
            } elseif ($seen['reason'] === null) {
                $seen['reason'] = $result['reason'] ?? null;
            }
            $byClient[$device->client_id] = $seen;
        }

        return $byClient;
    }
}
