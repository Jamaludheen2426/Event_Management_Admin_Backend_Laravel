<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\GalleryCategory;
use App\Modules\Events\Models\GalleryItem;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\MediaQuota;
use App\Modules\Events\Support\MediaTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * An event's photo and video gallery (v1 clientGallery.service).
 *
 * Albums: every event has a default "Gallery" album that cannot be renamed or
 * deleted; items of a deleted album move into it. Uploads: images up to 2MB,
 * videos up to 5MB, within the plan's photo/video counts and the host's
 * storage; images get a thumbnail, videos are made playable (and muted on
 * request) and get a poster. Guests see only items shown in the app.
 */
final class Gallery
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    private const DEFAULT_ALBUM = 'Gallery';

    public function __construct(
        private readonly EventAccess $access,
        private readonly ObjectStorage $storage,
        private readonly MediaQuota $quota,
        private readonly MediaTools $media,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  type, category_id
     * @return array<string, mixed>
     */
    public function list(Client $actor, int $eventId, array $filters): array
    {
        [$event, $manages] = $this->access->viewable($actor, $eventId);
        $default = $this->defaultAlbum($event);
        $query = $this->ordered($event)
            ->when(in_array($filters['type'] ?? null, ['image', 'video'], true), static fn ($q) => $q->where('type', $filters['type']))
            ->when(! $manages, static fn ($q) => $q->where('show_in_app', true));
        if (($filters['category_id'] ?? '') !== '' && $filters['category_id'] !== null) {
            $query->where('category_id', (int) $filters['category_id'] > 0 ? (int) $filters['category_id'] : $default->id);
        }

        return [
            'items' => $query->get()->map(fn (GalleryItem $i): array => $this->present($i))->all(),
            'categories' => $this->albums($event, visibleOnly: ! $manages),
            'usage' => $this->quota->usage($event),
            'can_upload' => $manages,
        ];
    }

    /** @return array<string, mixed> */
    public function usage(Client $actor, int $eventId): array
    {
        [$event] = $this->access->viewable($actor, $eventId);

        return $this->quota->usage($event);
    }

    /** @return list<array<string, mixed>> */
    public function categories(Client $actor, int $eventId): array
    {
        [$event, $manages] = $this->access->viewable($actor, $eventId);

        return $this->albums($event, visibleOnly: ! $manages);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function upload(Client $actor, int $eventId, ?UploadedFile $file, array $input): array
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose a file to upload.');
        }
        $event = $this->managed($actor, $eventId);
        $mime = (string) $file->getMimeType();
        $isImage = in_array($mime, self::IMAGE_MIMES, true);
        if (! $isImage && ! in_array($mime, self::VIDEO_MIMES, true)) {
            throw ApiException::badRequest('Please choose a JPG, PNG, WEBP or GIF image, or an MP4, WebM or MOV video.');
        }
        $type = $isImage ? 'image' : 'video';
        $size = (int) $file->getSize();
        $cap = $isImage ? MediaQuota::MAX_IMAGE_BYTES : MediaQuota::MAX_VIDEO_BYTES;
        if ($size > $cap) {
            throw ApiException::badRequest(($isImage ? 'Images' : 'Videos').' must be '.MediaQuota::mb($cap).'MB or smaller. This file is '.MediaQuota::mb($size).'MB.');
        }
        $limit = $this->quota->limit($event, $isImage ? 'max_photos' : 'max_videos');
        $limitMessage = "This event has reached its limit of {$limit} {$type}".($limit === 1 ? '' : 's').'. Please upgrade your plan to add more.';
        if ($this->isFull($event, $isImage, $limit)) {
            throw ApiException::badRequest($limitMessage);
        }
        $this->quota->assertStorage($event, $size);

        $bytes = (string) $file->get();
        $name = $file->getClientOriginalName();
        if (! $isImage) {
            $mute = in_array($input['mute'] ?? null, [true, 'true', 1, '1'], true);
            $out = $this->media->processVideo($bytes, $mime, $mute);
            if ($mute && ! $out['muted']) {
                throw ApiException::badRequest('The sound could not be removed from this video. Please try again.');
            }
            if ($out['applied'] && strlen($out['bytes']) <= MediaQuota::MAX_VIDEO_BYTES) {
                [$bytes, $mime] = [$out['bytes'], $out['mimetype']];
                $name = pathinfo($name, PATHINFO_FILENAME).($mime === 'video/webm' ? '.webm' : '.mp4');
            } elseif ($mute && $out['applied']) {
                throw ApiException::badRequest('Videos must be '.MediaQuota::mb(MediaQuota::MAX_VIDEO_BYTES).'MB or smaller.');
            }
        }

        $folder = 'event-gallery/'.$event->id;
        $stored = $this->storage->put($bytes, $mime, $name, $folder);
        $thumbBytes = $isImage ? $this->media->imageThumb($bytes) : $this->media->videoPoster($bytes);
        $thumb = $thumbBytes !== null ? $this->storage->put($thumbBytes, 'image/jpeg', pathinfo($name, PATHINFO_FILENAME).($isImage ? '-thumb.jpg' : '-poster.jpg'), $folder)['url'] : null;

        $item = DB::transaction(function () use ($event, $actor, $type, $stored, $thumb, $name, $mime, $bytes, $input, $isImage, $limit, $limitMessage): GalleryItem {
            Event::query()->whereKey($event->id)->lockForUpdate()->value('id');
            if ($this->isFull($event, $isImage, $limit)) {
                throw ApiException::badRequest($limitMessage);
            }

            return GalleryItem::query()->create([
                'event_id' => $event->id,
                'type' => $type,
                'url' => $stored['url'],
                'thumbnail_url' => $thumb,
                'file_name' => $name,
                'mime_type' => $mime,
                'size_bytes' => strlen($bytes),
                'category_id' => $this->album($event, $input['category_id'] ?? null),
                'caption' => is_string($input['caption'] ?? null) ? mb_substr($input['caption'], 0, 300) : null,
                'sort_order' => $this->nextSort($event->id),
                'uploaded_by' => $actor->id,
            ]);
        });

        return ['item' => $this->present($item), 'usage' => $this->quota->usage($event)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(Client $actor, int $itemId, array $input): array
    {
        $item = $this->item($actor, $itemId);
        if (array_key_exists('caption', $input)) {
            $item->caption = is_string($input['caption']) && trim($input['caption']) !== '' ? mb_substr(trim($input['caption']), 0, 300) : null;
        }
        if (array_key_exists('show_in_app', $input)) {
            $item->show_in_app = in_array($input['show_in_app'], [true, 'true', 1, '1'], true);
        }
        $event = Event::query()->with('client')->findOrFail($item->event_id);
        if (array_key_exists('category_id', $input)) {
            if ((int) $input['category_id'] > 0) {
                $item->category_id = GalleryCategory::query()->where('event_id', $event->id)->whereKey((int) $input['category_id'])->value('id')
                    ?? throw ApiException::notFound('That category was not found.');
            } else {
                $item->category_id = $this->defaultAlbum($event)->id;
            }
        }
        $item->save();

        return ['item' => $this->present($item), 'categories' => $this->albums($event)];
    }

    /** @return array<string, mixed> */
    public function delete(Client $actor, int $itemId): array
    {
        $item = $this->item($actor, $itemId);
        $this->storage->deleteByUrl($item->url);
        if ($item->thumbnail_url !== null && $item->thumbnail_url !== $item->url) {
            $this->storage->deleteByUrl($item->thumbnail_url);
        }
        $item->forceDelete();

        return ['removed' => true, 'usage' => $this->quota->usage(Event::query()->with('client')->findOrFail($item->event_id))];
    }

    /**
     * The moved items take the moved items' slots, in the order sent; every
     * other item keeps its place (v1).
     *
     * @return array{reordered: true}
     */
    public function reorder(Client $actor, int $eventId, mixed $ids): array
    {
        $event = $this->managed($actor, $eventId);
        if (! is_array($ids)) {
            throw ApiException::badRequest('Invalid gallery order.');
        }
        $all = $this->ordered($event)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $sent = array_values(array_intersect(array_unique(array_map('intval', $ids)), $all));
        $next = 0;
        $order = array_map(static function (int $id) use ($sent, &$next): int {
            return in_array($id, $sent, true) ? $sent[$next++] : $id;
        }, $all);
        if ($order !== []) {
            $cases = implode(' ', array_map(static fn (int $id, int $i): string => "when {$id} then {$i}", $order, array_keys($order)));
            GalleryItem::query()->where('event_id', $event->id)->whereIn('id', $order)->update(['sort_order' => DB::raw("case id {$cases} end")]);
        }

        return ['reordered' => true];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createAlbum(Client $actor, int $eventId, array $input): array
    {
        $event = $this->managed($actor, $eventId);
        $this->defaultAlbum($event);
        $name = mb_substr(trim(is_string($input['name'] ?? null) ? $input['name'] : ''), 0, 100) ?: throw ApiException::badRequest('Please enter a category name.');
        $this->assertAlbumFree($event->id, $name);

        $album = GalleryCategory::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'description' => $this->text($input['description'] ?? null, 255),
            'cover_image' => $this->text($input['cover_image'] ?? null, 500),
            'icon' => is_string($input['icon'] ?? null) ? mb_substr($input['icon'], 0, 100) : null,
            'sort_order' => (int) GalleryCategory::query()->where('event_id', $event->id)->max('sort_order') + 1,
        ]);
        if ($this->hasPosition($input)) {
            $this->place($event->id, $album->id, $input['position']);
            $album->refresh();
        }

        return $this->presentAlbum($album);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateAlbum(Client $actor, int $albumId, array $input): array
    {
        $album = $this->ownAlbum($actor, $albumId);
        if ($album->is_default && array_key_exists('name', $input) && trim((string) $input['name']) !== $album->name) {
            throw ApiException::badRequest('The Gallery category cannot be renamed.');
        }
        if (array_key_exists('name', $input) && ! $album->is_default) {
            $name = mb_substr(trim(is_string($input['name']) ? $input['name'] : ''), 0, 100) ?: throw ApiException::badRequest('Please enter a category name.');
            $this->assertAlbumFree($album->event_id, $name, $album->id);
            $album->name = $name;
        }
        if (array_key_exists('description', $input)) {
            $album->description = $this->text($input['description'], 255);
        }
        $oldCover = null;
        if (array_key_exists('cover_image', $input)) {
            $next = $this->text($input['cover_image'], 500);
            $oldCover = $album->cover_image !== null && $album->cover_image !== $next ? $album->cover_image : null;
            $album->cover_image = $next;
        }
        $album->save();
        if ($oldCover !== null) {
            $this->storage->deleteByUrl($oldCover);
        }
        if ($this->hasPosition($input) && ! $album->is_default) {
            $this->place($album->event_id, $album->id, $input['position']);
        }
        $albums = $this->albums(Event::query()->with('client')->findOrFail($album->event_id));

        return ['category' => collect($albums)->firstWhere('id', $album->id), 'categories' => $albums];
    }

    /** @return array<string, mixed> */
    public function deleteAlbum(Client $actor, int $albumId): array
    {
        $album = $this->ownAlbum($actor, $albumId);
        if ($album->is_default) {
            throw ApiException::badRequest('The Gallery category cannot be deleted.');
        }
        $event = Event::query()->with('client')->findOrFail($album->event_id);
        GalleryItem::query()->where('category_id', $album->id)->update(['category_id' => $this->defaultAlbum($event)->id]);
        $album->forceDelete();
        if ($album->cover_image !== null) {
            $this->storage->deleteByUrl($album->cover_image);
        }

        return ['removed' => true, 'categories' => $this->albums($event)];
    }

    /** @return array{url: string} */
    public function uploadCover(Client $actor, int $eventId, ?UploadedFile $file): array
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose an image to upload.');
        }
        $event = $this->managed($actor, $eventId);
        if (! in_array($file->getMimeType(), self::IMAGE_MIMES, true)) {
            throw ApiException::badRequest('Please choose a JPG, PNG, WEBP or GIF image.');
        }
        if ($file->getSize() > MediaQuota::MAX_IMAGE_BYTES) {
            throw ApiException::badRequest('Images must be '.MediaQuota::mb(MediaQuota::MAX_IMAGE_BYTES).'MB or smaller. This file is '.MediaQuota::mb((int) $file->getSize()).'MB.');
        }

        return ['url' => $this->storage->upload($file, 'event-gallery/'.$event->id.'/covers')['url']];
    }

    /** Remove an event's gallery files from storage (the event is being deleted). */
    public function purge(int $eventId): void
    {
        foreach (GalleryItem::query()->withTrashed()->where('event_id', $eventId)->get(['url', 'thumbnail_url']) as $item) {
            $this->storage->deleteByUrl($item->url);
            if ($item->thumbnail_url !== null && $item->thumbnail_url !== $item->url) {
                $this->storage->deleteByUrl($item->thumbnail_url);
            }
        }
        foreach (GalleryCategory::query()->withTrashed()->where('event_id', $eventId)->whereNotNull('cover_image')->pluck('cover_image') as $cover) {
            $this->storage->deleteByUrl((string) $cover);
        }
        GalleryItem::query()->withTrashed()->where('event_id', $eventId)->forceDelete();
        GalleryCategory::query()->withTrashed()->where('event_id', $eventId)->forceDelete();
    }

    /** The event's default album, made (or adopted from a "Gallery" album) when missing. */
    private function defaultAlbum(Event $event): GalleryCategory
    {
        $album = GalleryCategory::query()->where('event_id', $event->id)->where('is_default', true)->first();
        if ($album === null) {
            $album = GalleryCategory::query()->where('event_id', $event->id)->where('name', self::DEFAULT_ALBUM)->first()
                ?? new GalleryCategory(['event_id' => $event->id, 'name' => self::DEFAULT_ALBUM, 'sort_order' => 0]);
            $album->is_default = true;
            $album->save();
            GalleryItem::query()->where('event_id', $event->id)->whereNull('category_id')->update(['category_id' => $album->id]);
        }

        return $album;
    }

    /** @return list<array<string, mixed>> albums with item counts and a cover (their own, else the first photo) */
    private function albums(Event $event, bool $visibleOnly = false): array
    {
        $this->defaultAlbum($event);
        $items = GalleryItem::query()->where('event_id', $event->id)->when($visibleOnly, static fn ($q) => $q->where('show_in_app', true));
        $counts = (clone $items)->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');
        $firstPhoto = (clone $items)->where('type', 'image')->whereNotNull('category_id')->orderBy('sort_order')->orderByDesc('id')
            ->get(['category_id', 'url', 'thumbnail_url'])->unique('category_id')->mapWithKeys(static fn (GalleryItem $i): array => [$i->category_id => $i->thumbnail_url ?? $i->url]);

        return GalleryCategory::query()->where('event_id', $event->id)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (GalleryCategory $c): array => $this->presentAlbum($c, (int) ($counts[$c->id] ?? 0), $firstPhoto[$c->id] ?? null))->all();
    }

    /** @return array<string, mixed> */
    private function presentAlbum(GalleryCategory $c, int $count = 0, ?string $firstPhoto = null): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'cover_image' => $c->cover_image,
            'cover_url' => $c->cover_image ?? $firstPhoto, 'icon' => $c->icon, 'sort_order' => $c->sort_order,
            'is_default' => $c->is_default, 'item_count' => $count,
        ];
    }

    /** @return array<string, mixed> */
    private function present(GalleryItem $i): array
    {
        return [
            'id' => $i->id, 'event_id' => $i->event_id, 'type' => $i->type, 'url' => $i->url,
            'thumbnail_url' => $i->thumbnail_url ?? ($i->type === 'image' ? $i->url : null),
            'file_name' => $i->file_name, 'mime_type' => $i->mime_type, 'size_bytes' => $i->size_bytes, 'category_id' => $i->category_id,
            'caption' => $i->caption, 'show_in_app' => $i->show_in_app, 'sort_order' => $i->sort_order,
            'created_at' => $i->created_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** @return Builder<GalleryItem> */
    private function ordered(Event $event): Builder
    {
        return GalleryItem::query()->where('event_id', $event->id)->orderBy('sort_order')->orderByDesc('id');
    }

    private function album(Event $event, mixed $id): int
    {
        $found = is_numeric($id) && (int) $id > 0 ? GalleryCategory::query()->where('event_id', $event->id)->whereKey((int) $id)->value('id') : null;

        return $found ?? $this->defaultAlbum($event)->id;
    }

    private function ownAlbum(Client $actor, int $albumId): GalleryCategory
    {
        return GalleryCategory::query()->whereIn('event_id', $this->access->managedEventIds($actor))->find($albumId)
            ?? throw ApiException::notFound('That category was not found.');
    }

    private function assertAlbumFree(int $eventId, string $name, ?int $except = null): void
    {
        if (GalleryCategory::query()->where('event_id', $eventId)->where('name', $name)->when($except !== null, static fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ApiException::conflict("\"{$name}\" is already a category on this event.");
        }
    }

    /** Move an album to a 1-based position among the event's albums. */
    private function place(int $eventId, int $albumId, mixed $position): void
    {
        $ids = GalleryCategory::query()->where('event_id', $eventId)->orderBy('sort_order')->orderBy('id')->pluck('id')
            ->map(static fn ($id): int => (int) $id)->reject(static fn (int $id): bool => $id === $albumId)->values()->all();
        $index = is_numeric($position) && (int) $position >= 1 ? min((int) $position - 1, count($ids)) : count($ids);
        array_splice($ids, $index, 0, [$albumId]);
        $cases = implode(' ', array_map(static fn (int $id, int $i): string => "when {$id} then {$i}", $ids, array_keys($ids)));
        GalleryCategory::query()->where('event_id', $eventId)->whereIn('id', $ids)->update(['sort_order' => DB::raw("case id {$cases} end")]);
    }

    /** @param array<string, mixed> $input */
    private function hasPosition(array $input): bool
    {
        return isset($input['position']) && $input['position'] !== '';
    }

    private function isFull(Event $event, bool $isImage, ?int $limit): bool
    {
        if ($limit === null) {
            return false;
        }

        return ($isImage ? GalleryItem::query()->where('event_id', $event->id)->where('type', 'image')->count() : $this->quota->videosUsed($event->id)) >= $limit;
    }

    private function nextSort(int $eventId): int
    {
        return GalleryItem::query()->where('event_id', $eventId)->exists() ? (int) GalleryItem::query()->where('event_id', $eventId)->max('sort_order') + 1 : 0;
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private function managed(Client $actor, int $eventId): Event
    {
        $event = Event::query()->with('client')->find($eventId);
        if ($event === null || ! $this->access->canManage($actor, $event)) {
            throw ApiException::notFound('That event is not on your account.');
        }

        return $event;
    }

    private function item(Client $actor, int $itemId): GalleryItem
    {
        return GalleryItem::query()->whereIn('event_id', $this->access->managedEventIds($actor))->find($itemId)
            ?? throw ApiException::notFound('That gallery item was not found.');
    }
}
