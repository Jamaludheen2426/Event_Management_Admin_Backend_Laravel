<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Core\Exceptions\ApiException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\GalleryItem;
use App\Modules\Events\Models\Wish;

/**
 * Plan limits on event media (v1 clientGallery storage/limit helpers):
 * photos and videos per event (gallery videos and video wishes count
 * together), and the host's total storage across live events.
 */
final class MediaQuota
{
    public const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    public const MAX_VIDEO_BYTES = 5 * 1024 * 1024;

    public function plan(Event $event): ?Plan
    {
        $event->loadMissing('client');
        $id = $event->plan_id ?? $event->client->plan_id;

        return $id !== null ? Plan::query()->withoutGlobalScopes()->withTrashed()->find($id) : null;
    }

    public function limit(Event $event, string $key): ?int
    {
        $value = $this->plan($event)?->getAttribute($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** Bytes of gallery files and wish media across the host's live events. */
    public function storageUsed(int $hostId): int
    {
        $events = Event::query()->withoutGlobalScopes()->where('client_id', $hostId)->whereNull('deleted_at')->pluck('id');

        return (int) GalleryItem::query()->whereIn('event_id', $events)->sum('size_bytes') + (int) Wish::query()->whereIn('event_id', $events)->sum('size_bytes');
    }

    /** The host's storage allowance in bytes (null = unlimited). */
    public function storageLimit(Event $event): ?int
    {
        $event->loadMissing('client');
        $plan = $event->client->plan_id !== null ? Plan::query()->withoutGlobalScopes()->withTrashed()->find($event->client->plan_id) : null;
        $limit = $plan?->getAttribute('storage_limit');
        if (! is_numeric($limit)) {
            return null;
        }

        return (int) $limit * ($plan->getAttribute('storage_unit') === 'GB' ? 1024 ** 3 : 1024 ** 2);
    }

    public function videosUsed(int $eventId): int
    {
        return GalleryItem::query()->where('event_id', $eventId)->where('type', 'video')->count()
            + Wish::query()->where('event_id', $eventId)->where('post_type', 'video')->count();
    }

    public function assertStorage(Event $event, int $adding): void
    {
        $limit = $this->storageLimit($event);
        if ($limit === null) {
            return;
        }
        $used = $this->storageUsed($event->client_id);
        if ($used + $adding > $limit) {
            throw ApiException::badRequest('Your plan includes '.self::mb($limit).'MB of storage and '.self::mb(max(0, $limit - $used)).'MB is left. This file is '.self::mb($adding).'MB.');
        }
    }

    /** @return array<string, mixed> the gallery usage panel */
    public function usage(Event $event): array
    {
        $used = $this->storageUsed($event->client_id);
        $limit = $this->storageLimit($event);

        return [
            'photos' => ['used' => GalleryItem::query()->where('event_id', $event->id)->where('type', 'image')->count(), 'limit' => $this->limit($event, 'max_photos')],
            'videos' => ['used' => $this->videosUsed($event->id), 'limit' => $this->limit($event, 'max_videos')],
            'storage' => ['used_bytes' => $used, 'limit_bytes' => $limit, 'used_mb' => self::mb($used), 'limit_mb' => $limit === null ? null : self::mb($limit)],
            'max_image_bytes' => self::MAX_IMAGE_BYTES,
            'max_video_bytes' => self::MAX_VIDEO_BYTES,
        ];
    }

    public static function mb(int $bytes): float
    {
        return round($bytes / (1024 * 1024), 1);
    }
}
