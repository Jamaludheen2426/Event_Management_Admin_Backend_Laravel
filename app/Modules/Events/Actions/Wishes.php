<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Phone;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventSetting;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Models\Wish;
use App\Modules\Events\Models\WishBlock;
use App\Modules\Events\Models\WishLike;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\MediaQuota;
use App\Modules\Events\Support\MediaTools;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Wishes on an event (v1 clientWish.service, "Wed Wishes").
 *
 * Participants post text, a photo or a short video; the host (and organizer)
 * moderate: hide, mark reviewed, block a writer (their Wishes tile disappears
 * too), and run a word filter that masks listed words and can auto-hide the
 * wish. The host cannot edit a participant's words — only their own posts.
 * Guests see what the host's display settings allow.
 */
final class Wishes
{
    private const DEFAULTS = ['allow_new' => true, 'allow_photo' => true, 'details_screen' => true, 'profile_photo' => true, 'name' => true, 'wish_photo' => true, 'time' => true];

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];

    private const NO_MEDIA = ['photo' => null, 'video_url' => null, 'thumbnail_url' => null, 'duration_seconds' => null, 'size_bytes' => 0];

    public function __construct(
        private readonly EventAccess $access,
        private readonly ObjectStorage $storage,
        private readonly MediaQuota $quota,
        private readonly MediaTools $media,
    ) {}

    /** @return array<string, mixed> */
    public function list(Client $actor, int $eventId): array
    {
        [$event, $manages] = $this->open($actor, $eventId);
        $rows = Wish::query()->where('event_id', $event->id)
            ->when(! $manages, static fn ($q) => $q->where(static fn ($w) => $w->where('is_hidden', false)->orWhere('author_client_id', $actor->id)))
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        $ctx = $this->context($event->id, $rows, $actor->id);
        $blocked = ! $manages && $ctx['blocked']->contains($actor->id);
        if ($blocked && $ctx['hiddenMenu']->contains($actor->id)) {
            throw ApiException::forbidden('Wed Wishes is not available for you on this event.');
        }
        $settings = $this->settings($event);
        $wishes = $rows->map(fn (Wish $w): array => $this->present($w, $manages, $actor->id, $ctx, $settings))->all();

        $result = ['wishes' => $wishes, 'settings' => $settings, 'can_manage' => $manages, 'is_blocked' => $blocked, 'can_write' => $manages || ($settings['allow_new'] && ! $blocked)];
        if ($manages) {
            $count = static fn (string $s): int => count(array_filter($wishes, static fn (array $w): bool => $w['status'] === $s));
            $result['counts'] = [
                'all' => count($wishes), 'visible' => $count('visible'), 'hidden' => $count('hidden'), 'blocked' => $count('blocked'),
                'blocked_users' => $ctx['blocked']->count(), 'flagged' => count(array_filter($wishes, static fn (array $w): bool => $w['is_flagged'])),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function show(Client $actor, int $wishId): array
    {
        [$wish, $event, $manages] = $this->wish($actor, $wishId);
        $mine = $wish->author_client_id === $actor->id;
        if (! $manages && ! $mine && $wish->is_hidden) {
            throw ApiException::notFound('That wish was not found.');
        }
        if (! $manages && ! $mine && ! $this->settings($event)['details_screen']) {
            throw ApiException::forbidden('Wish details are turned off for this event.');
        }

        return $this->presentOne($wish, $actor, $manages, $event);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Client $actor, int $eventId, array $input, ?UploadedFile $file): array
    {
        [$event, $manages] = $this->open($actor, $eventId);
        $settings = $this->settings($event);
        if (! $manages) {
            if ($this->isBlocked($event->id, $actor->id)) {
                throw ApiException::forbidden('You can no longer send wishes for this event.');
            }
            if (! $settings['allow_new']) {
                throw ApiException::forbidden('The organizer has closed wishes for this event.');
            }
            if ($file !== null && ! $settings['allow_photo']) {
                throw ApiException::badRequest('Photos and videos are turned off for wishes on this event.');
            }
        }
        $message = $this->str($input['message'] ?? null, 500);
        $type = in_array($input['post_type'] ?? null, ['text', 'image', 'video'], true) ? $input['post_type'] : ($file !== null ? 'image' : 'text');
        if ($type !== 'text' && $file === null) {
            throw ApiException::badRequest('Please choose a photo or video.');
        }
        if ($type === 'text' && $message === null) {
            throw ApiException::badRequest('Please fill all mandatory fields.');
        }

        if ($manages) {
            $who = ['author_client_id' => null, 'guest_name' => $this->str($input['guest_name'] ?? null, 150) ?? $this->str($event->organizer, 150) ?? $this->str($actor->name, 150) ?? 'Organizer', 'mobile' => null];
        } else {
            $participant = $this->participant($actor, $event->id);
            $who = ['author_client_id' => $actor->id, 'guest_name' => $this->str($participant?->name, 150) ?? $this->str($actor->name, 150) ?? 'Participant', 'mobile' => $participant->mobile ?? null];
        }

        $filter = $this->filter($event);
        [$text, $flagged] = $this->applyFilter((string) $message, $filter);
        $flagged = $flagged && ! $manages;
        $media = $file !== null ? $this->storeMedia($event, $file, $input, null) : [...self::NO_MEDIA, 'post_type' => 'text'];

        $wish = Wish::query()->create([
            ...$who, ...$media,
            'audience' => in_array($input['audience'] ?? null, ['groom', 'bride', 'both'], true) ? $input['audience'] : 'both',
            'message' => $text,
            'is_hidden' => $flagged && $filter['auto_hide'],
            'is_flagged' => $flagged,
            'event_id' => $event->id,
        ]);
        if ($flagged && $filter['notify']) {
            $this->notifyFlagged($event, $wish);
        }

        return $this->presentOne($wish, $actor, $manages, $event);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(Client $actor, int $wishId, array $input): array
    {
        [$wish, $event, $manages] = $this->wish($actor, $wishId);
        $has = static fn (string $f): bool => array_key_exists($f, $input);
        if ($has('message') || $has('audience') || $has('post_type') || ! $manages) {
            $this->assertCanEdit($wish, $event, $manages, $actor);
        }
        $values = [];
        $toText = ($input['post_type'] ?? null) === 'text' && $wish->post_type !== 'text';
        $notify = false;
        if ($has('message')) {
            $filter = $this->filter($event);
            [$values['message'], $flagged] = $this->applyFilter($this->str($input['message'], 500) ?? '', $filter);
            if ($flagged && ! $manages) {
                $notify = $filter['notify'];
                $values['is_flagged'] = true;
                if ($filter['auto_hide']) {
                    $values['is_hidden'] = true;
                }
            }
        }
        if (($toText || ($wish->post_type === 'text' && $has('message'))) && ($values['message'] ?? $wish->message) === '') {
            throw ApiException::badRequest('Please fill all mandatory fields.');
        }
        if ($has('audience')) {
            $values['audience'] = in_array($input['audience'], ['groom', 'bride', 'both'], true) ? $input['audience'] : $wish->audience;
        }
        if ($manages && $has('show_in_app')) {
            $values['is_hidden'] = ! (bool) $input['show_in_app'];
            if ($input['show_in_app']) {
                $values['is_flagged'] = false;
            }
        }
        if ($manages && $this->bool($input['reviewed'] ?? null, false)) {
            $values['is_flagged'] = false;
        }
        $before = $wish->only(['photo', 'video_url', 'thumbnail_url']);
        if ($toText) {
            $values = [...$values, ...self::NO_MEDIA, 'post_type' => 'text'];
        }
        $wish->fill($values)->save();
        if ($toText) {
            $this->dropMedia($before);
        }
        if ($notify) {
            $this->notifyFlagged($event, $wish);
        }
        if ($manages && ($values['is_hidden'] ?? null) === true && $wish->author_client_id !== null && $this->bool($input['all_from_writer'] ?? null, false)) {
            Wish::query()->where('event_id', $event->id)->where('author_client_id', $wish->author_client_id)->where('is_hidden', false)->update(['is_hidden' => true]);
        }

        return $this->presentOne($wish, $actor, $manages, $event);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function replaceMedia(Client $actor, int $wishId, ?UploadedFile $file, array $input): array
    {
        [$wish, $event, $manages] = $this->wish($actor, $wishId);
        $this->assertCanEdit($wish, $event, $manages, $actor);
        if (! $manages && ! $this->settings($event)['allow_photo']) {
            throw ApiException::badRequest('Photos and videos are turned off for wishes on this event.');
        }
        $before = $wish->only(['photo', 'video_url', 'thumbnail_url']);
        $wish->fill($this->storeMedia($event, $file, $input, $wish))->save();
        $this->dropMedia($before);

        return $this->presentOne($wish, $actor, $manages, $event);
    }

    /** @return array{removed: true} */
    public function delete(Client $actor, int $wishId): array
    {
        [$wish, , $manages] = $this->wish($actor, $wishId);
        if (! $manages && $wish->author_client_id !== $actor->id) {
            throw ApiException::forbidden('Only the organizer or the writer can delete this wish.');
        }
        $wish->delete();
        $this->dropMedia($wish->only(['photo', 'video_url', 'thumbnail_url']));

        return ['removed' => true];
    }

    /** @return array<string, mixed> */
    public function like(Client $actor, int $wishId, bool $liked): array
    {
        [$wish, $event, $manages] = $this->wish($actor, $wishId);
        if (! $manages && $wish->author_client_id !== $actor->id && $wish->is_hidden) {
            throw ApiException::notFound('That wish was not found.');
        }
        if (! $manages && $this->isBlocked($event->id, $actor->id)) {
            throw ApiException::forbidden('You can no longer send wishes for this event.');
        }
        $liked
            ? WishLike::query()->firstOrCreate(['wish_id' => $wish->id, 'client_id' => $actor->id])
            : WishLike::query()->where('wish_id', $wish->id)->where('client_id', $actor->id)->delete();

        return $this->presentOne($wish, $actor, $manages, $event);
    }

    /** @return array{wish: array<string, mixed>|null, removed: int} */
    public function block(Client $actor, int $wishId, bool $blocked, bool $removeWishes): array
    {
        [$wish, $event, $manages] = $this->wish($actor, $wishId);
        if (! $manages) {
            throw ApiException::forbidden('Only the organizer can block a participant.');
        }
        if ($wish->author_client_id === null) {
            throw ApiException::badRequest('This is your own post — there is no participant to block.');
        }
        $where = ['event_id' => $event->id, 'author_client_id' => $wish->author_client_id];
        if (! $blocked) {
            WishBlock::query()->where($where)->delete();

            return ['wish' => $this->presentOne($wish, $actor, true, $event), 'removed' => 0];
        }
        WishBlock::query()->updateOrCreate($where, ['hide_menu' => true]);
        if ($removeWishes) {
            $theirs = Wish::query()->where($where)->get();
            $theirs->each(fn (Wish $w) => $this->dropMedia($w->only(['photo', 'video_url', 'thumbnail_url'])));

            return ['wish' => null, 'removed' => Wish::query()->where($where)->delete()];
        }

        return ['wish' => $this->presentOne($wish, $actor, true, $event), 'removed' => 0];
    }

    /** @return array{event_id: int, settings: array<string, bool>} */
    public function viewSettings(Client $actor, int $eventId): array
    {
        [$event] = $this->open($actor, $eventId);

        return ['event_id' => $event->id, 'settings' => $this->settings($event)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{event_id: int, settings: array<string, bool>}
     */
    public function updateSettings(Client $actor, int $eventId, array $input): array
    {
        $event = $this->managed($actor, $eventId, 'Only the organizer can change wish settings.');
        $current = $this->settings($event);
        $next = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $next[$key] = $this->bool($input[$key] ?? null, $current[$key]);
        }
        $next['name'] = true;
        EventSetting::query()->updateOrCreate(['event_id' => $event->id], ['wish' => $next]);

        return ['event_id' => $event->id, 'settings' => $next];
    }

    /** @return array{event_id: int, filter: array<string, mixed>} */
    public function viewFilter(Client $actor, int $eventId): array
    {
        $event = $this->managed($actor, $eventId, 'Only the organizer can see the content filter.');

        return ['event_id' => $event->id, 'filter' => $this->filter($event)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{event_id: int, filter: array<string, mixed>}
     */
    public function updateFilter(Client $actor, int $eventId, array $input): array
    {
        $event = $this->managed($actor, $eventId, 'Only the organizer can change the content filter.');
        if (array_key_exists('words', $input) && ! is_array($input['words'])) {
            throw ApiException::badRequest('Invalid word list.');
        }
        $current = $this->filter($event);
        $filter = $this->cleanFilter([
            'enabled' => $this->bool($input['enabled'] ?? null, $current['enabled']),
            'words' => $input['words'] ?? $current['words'],
            'auto_hide' => $this->bool($input['auto_hide'] ?? null, $current['auto_hide']),
            'notify' => $this->bool($input['notify'] ?? null, $current['notify']),
        ]);
        EventSetting::query()->updateOrCreate(['event_id' => $event->id], ['wish_filter' => $filter]);

        return ['event_id' => $event->id, 'filter' => $filter];
    }

    /** Remove an event's wishes and their files (the event is being deleted). */
    public function purge(int $eventId): void
    {
        Wish::query()->where('event_id', $eventId)->get()->each(fn (Wish $w) => $this->dropMedia($w->only(['photo', 'video_url', 'thumbnail_url'])));
        Wish::query()->where('event_id', $eventId)->delete();
    }

    /** @return array<string, bool> */
    public function settings(Event $event): array
    {
        $event->loadMissing('settings');
        $stored = $event->settings->wish ?? [];
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = $this->bool($stored[$key] ?? null, $default);
        }
        $out['name'] = true;

        return $out;
    }

    /** @return array{enabled: bool, words: list<array{word: string, replace: string}>, auto_hide: bool, notify: bool} */
    private function filter(Event $event): array
    {
        $event->loadMissing('settings');

        return $this->cleanFilter($event->settings->wish_filter ?? []);
    }

    /**
     * At most 200 words of 60 characters, case-insensitively unique; "***" when no replacement.
     *
     * @param  array<string, mixed>  $raw
     * @return array{enabled: bool, words: list<array{word: string, replace: string}>, auto_hide: bool, notify: bool}
     */
    private function cleanFilter(array $raw): array
    {
        $words = [];
        $seen = [];
        foreach (is_array($raw['words'] ?? null) ? $raw['words'] : [] as $entry) {
            $word = is_array($entry) ? $this->str($entry['word'] ?? null, 60) : null;
            if ($word === null || isset($seen[mb_strtolower($word)])) {
                continue;
            }
            $seen[mb_strtolower($word)] = true;
            $replace = is_string($entry['replace'] ?? null) ? mb_substr(trim($entry['replace']), 0, 60) : '';
            $words[] = ['word' => $word, 'replace' => $replace !== '' ? $replace : '***'];
            if (count($words) >= 200) {
                break;
            }
        }

        return ['enabled' => $this->bool($raw['enabled'] ?? null, false), 'words' => $words, 'auto_hide' => $this->bool($raw['auto_hide'] ?? null, true), 'notify' => $this->bool($raw['notify'] ?? null, true)];
    }

    /**
     * Mask listed words as whole words in any script, case-insensitively.
     *
     * @param  array{enabled: bool, words: list<array{word: string, replace: string}>, auto_hide: bool, notify: bool}  $filter
     * @return array{0: string, 1: bool} the masked text, and whether anything matched
     */
    private function applyFilter(string $text, array $filter): array
    {
        if (! $filter['enabled'] || $text === '' || $filter['words'] === []) {
            return [$text, false];
        }
        $flagged = false;
        foreach ($filter['words'] as ['word' => $word, 'replace' => $replace]) {
            $text = (string) preg_replace_callback('/(?<![\p{L}\p{N}\p{M}])'.preg_quote($word, '/').'(?![\p{L}\p{N}\p{M}])/iu', static function () use (&$flagged, $replace): string {
                $flagged = true;

                return $replace;
            }, $text);
        }

        return [$text, $flagged];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function storeMedia(Event $event, ?UploadedFile $file, array $input, ?Wish $replacing): array
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose a photo or video.');
        }
        $mime = (string) $file->getMimeType();
        $isImage = in_array($mime, self::IMAGE_MIMES, true);
        if (! $isImage && ! in_array($mime, self::VIDEO_MIMES, true)) {
            throw ApiException::badRequest('Please choose a JPG, PNG or WEBP image, or an MP4, WebM or MOV video.');
        }
        $size = (int) $file->getSize();
        $cap = $isImage ? MediaQuota::MAX_IMAGE_BYTES : MediaQuota::MAX_VIDEO_BYTES;
        if ($size > $cap) {
            throw ApiException::badRequest(($isImage ? 'Images' : 'Videos').' must be '.MediaQuota::mb($cap).'MB or smaller. This file is '.MediaQuota::mb($size).'MB.');
        }
        if (! $isImage) {
            $limit = $this->quota->limit($event, 'max_videos');
            if ($limit !== null && $this->quota->videosUsed($event->id) - ($replacing?->post_type === 'video' ? 1 : 0) >= $limit) {
                throw ApiException::badRequest("This event has reached its limit of {$limit} video".($limit === 1 ? '' : 's').'. Please upgrade your plan to add more.');
            }
        }
        $this->quota->assertStorage($event, $size - ($replacing->size_bytes ?? 0));

        $bytes = (string) $file->get();
        $name = $file->getClientOriginalName() ?: 'wish';
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

        $folder = 'event-wishes/'.$event->id;
        $url = $this->storage->put($bytes, $mime, $name, $folder)['url'];
        $thumbBytes = $isImage ? $this->media->imageThumb($bytes) : $this->media->videoPoster($bytes);
        $thumb = $thumbBytes !== null ? $this->storage->put($thumbBytes, 'image/jpeg', pathinfo($name, PATHINFO_FILENAME).($isImage ? '-thumb.jpg' : '-poster.jpg'), $folder)['url'] : null;
        $duration = (int) round((float) ($input['duration_seconds'] ?? 0));

        return [
            'post_type' => $isImage ? 'image' : 'video',
            'photo' => $isImage ? $url : null,
            'video_url' => $isImage ? null : $url,
            'thumbnail_url' => $thumb,
            'duration_seconds' => ! $isImage && $duration > 0 && $duration < 3600 ? $duration : null,
            'size_bytes' => strlen($bytes),
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, bool>  $settings
     * @return array<string, mixed>
     */
    private function present(Wish $w, bool $manages, int $viewerId, array $ctx, array $settings): array
    {
        $author = $w->author_client_id;
        $mine = $author !== null && $author === $viewerId;
        $authorBlocked = $author !== null && $ctx['blocked']->contains($author);
        $whole = $manages || $mine;
        $media = $whole || $settings['wish_photo'];
        $person = $author !== null ? ($ctx['people'][$author] ?? null) : null;
        $status = $w->is_hidden ? 'hidden' : 'visible';
        if ($manages && $authorBlocked) {
            $status = 'blocked';
        }
        $iso = static fn ($d): ?string => $d?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $w->id, 'event_id' => $w->event_id, 'post_type' => $w->post_type, 'audience' => $w->audience, 'guest_name' => $w->guest_name,
            'mobile' => $whole ? $w->mobile : null, 'message' => $w->message,
            'photo' => $media ? $w->photo : null, 'video_url' => $media ? $w->video_url : null,
            'thumbnail_url' => $media ? $w->thumbnail_url : null, 'duration_seconds' => $media ? $w->duration_seconds : null,
            'avatar' => $person !== null && ($whole || $settings['profile_photo']) ? $person['photo'] : null,
            'like_count' => (int) ($ctx['likes'][$w->id] ?? 0), 'liked_by_me' => $ctx['mine']->contains($w->id),
            'is_hidden' => $w->is_hidden, 'is_flagged' => $manages && $w->is_flagged, 'status' => $status,
            'added_by_organizer' => $author === null, 'author_blocked' => $manages && $authorBlocked,
            'participant_id' => $manages && $person !== null ? $person['id'] : null, 'is_mine' => $mine,
            'can_edit' => ($manages && $author === null) || ($mine && ! $authorBlocked), 'can_delete' => $manages || $mine,
            'created_at' => $whole || $settings['time'] ? $iso($w->created_at) : null, 'updated_at' => $iso($w->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function presentOne(Wish $wish, Client $actor, bool $manages, Event $event): array
    {
        return $this->present($wish, $manages, $actor->id, $this->context($event->id, collect([$wish]), $actor->id), $this->settings($event));
    }

    /**
     * Likes, blocks and authors' photos for a set of wishes, in four queries.
     *
     * @param  Collection<int, Wish>  $rows
     * @return array<string, mixed>
     */
    private function context(int $eventId, Collection $rows, int $viewerId): array
    {
        $authors = $rows->pluck('author_client_id')->filter()->unique()->values()->all();
        $ids = $rows->pluck('id')->all();
        $people = [];
        foreach (Participant::query()->where('event_id', $eventId)->whereIn('client_id', $authors)->orderBy('id')->get(['id', 'client_id', 'photo']) as $p) {
            $seen = $people[$p->client_id] ?? null;
            if ($seen === null || ($seen['photo'] === null && $p->photo !== null)) {
                $people[(int) $p->client_id] = ['id' => $p->id, 'photo' => $p->photo];
            }
        }
        $blocks = WishBlock::query()->where('event_id', $eventId)->get(['author_client_id', 'hide_menu']);

        return [
            'people' => $people,
            'blocked' => $blocks->pluck('author_client_id')->map(static fn ($id): int => (int) $id),
            'hiddenMenu' => $blocks->where('hide_menu', true)->pluck('author_client_id')->map(static fn ($id): int => (int) $id),
            'likes' => $ids === [] ? [] : WishLike::query()->whereIn('wish_id', $ids)->selectRaw('wish_id, count(*) as n')->groupBy('wish_id')->pluck('n', 'wish_id')->all(),
            'mine' => $ids === [] ? collect() : WishLike::query()->whereIn('wish_id', $ids)->where('client_id', $viewerId)->pluck('wish_id')->map(static fn ($id): int => (int) $id),
        ];
    }

    private function assertCanEdit(Wish $wish, Event $event, bool $manages, Client $actor): void
    {
        if ($manages && $wish->author_client_id !== null) {
            throw ApiException::forbidden("The organizer cannot edit a participant's wish.");
        }
        if (! $manages && $wish->author_client_id !== $actor->id) {
            throw ApiException::forbidden('Only the writer can edit this wish.');
        }
        if (! $manages && $this->isBlocked($event->id, $actor->id)) {
            throw ApiException::forbidden('You can no longer send wishes for this event.');
        }
    }

    private function notifyFlagged(Event $event, Wish $wish): void
    {
        $snippet = mb_substr($wish->message, 0, 120);
        foreach (array_unique(array_filter([$event->client_id, $event->organizer_client_id])) as $clientId) {
            ClientNotice::dispatch((int) $clientId, 'wish_flagged', 'A wish was flagged', $wish->guest_name.($snippet !== '' ? ": {$snippet}" : ''), null, $event->id, null, ['wish_id' => $wish->id]);
        }
    }

    /** @return array{0: Event, 1: bool} */
    private function open(Client $actor, int $eventId): array
    {
        return $this->access->viewable($actor, $eventId);
    }

    /** @return array{0: Wish, 1: Event, 2: bool} */
    private function wish(Client $actor, int $wishId): array
    {
        $wish = Wish::query()->find($wishId) ?? throw ApiException::notFound('That wish was not found.');
        [$event, $manages] = $this->access->viewable($actor, $wish->event_id);

        return [$wish, $event, $manages];
    }

    private function managed(Client $actor, int $eventId, string $forbidden): Event
    {
        [$event, $manages] = $this->access->viewable($actor, $eventId);

        return $manages ? $event : throw ApiException::forbidden($forbidden);
    }

    private function participant(Client $actor, int $eventId): ?Participant
    {
        $numbers = array_values(array_filter(Phone::candidates($actor->mobile), static fn (string $d): bool => strlen($d) >= 7));

        return Participant::query()->where('event_id', $eventId)
            ->where(static fn ($q) => $numbers === [] ? $q->where('client_id', $actor->id) : $q->where('client_id', $actor->id)->orWhereIn('mobile', $numbers))
            ->orderBy('id')->first();
    }

    private function isBlocked(int $eventId, int $clientId): bool
    {
        return WishBlock::query()->where('event_id', $eventId)->where('author_client_id', $clientId)->exists();
    }

    /** @param array<string, string|null> $urls */
    private function dropMedia(array $urls): void
    {
        foreach (array_unique(array_filter($urls)) as $url) {
            $this->storage->deleteByUrl($url);
        }
    }

    private function str(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim(mb_substr(trim($value), 0, $max));
    }

    private function bool(mixed $value, bool $fallback): bool
    {
        return $value === null ? $fallback : in_array($value, [true, 1, '1', 'true'], true);
    }
}
