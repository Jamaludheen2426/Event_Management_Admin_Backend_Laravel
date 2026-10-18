<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\AgendaItem;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\EventMenus;
use App\Modules\Events\Support\Ordering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;

/**
 * An event's agenda (v1 clientAgenda.service). The host and organizer edit;
 * participants read. Items stay inside the event's dates; images are our
 * uploads, at most 10 per item, removed from storage when no item uses them.
 */
final class Agenda
{
    private const MAX_IMAGES = 10;

    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly EventAccess $access,
        private readonly ObjectStorage $storage,
    ) {}

    /** @return array<string, mixed> */
    public function list(Client $actor, int $eventId): array
    {
        [$event, $manages] = $this->access->viewable($actor, $eventId);

        return ['items' => $this->items($event), 'days' => $this->days($event), ...EventMenus::state($event, 'agenda'), 'can_edit' => $manages];
    }

    /** @return array<string, mixed> */
    public function show(Client $actor, int $itemId): array
    {
        $item = AgendaItem::query()->find($itemId) ?? throw ApiException::notFound('That agenda item was not found.');
        $this->access->viewable($actor, $item->event_id);

        return $this->present($item);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Client $actor, int $eventId, array $input): array
    {
        $event = $this->managed($actor, $eventId);
        $values = $this->normalise($input, $event, null);
        $next = (int) AgendaItem::query()->where('event_id', $event->id)->max('sort_order') + (AgendaItem::query()->where('event_id', $event->id)->exists() ? 1 : 0);

        return $this->present(AgendaItem::query()->create([...$values, 'event_id' => $event->id, 'sort_order' => $next]));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(Client $actor, int $itemId, array $input): array
    {
        $item = $this->item($actor, $itemId);
        $before = $item->images ?? [];
        $values = $this->normalise($input, Event::query()->findOrFail($item->event_id), $item);
        $item->fill($values)->save();
        if (isset($values['images'])) {
            $this->forget(array_diff($before, $values['images']));
        }

        return $this->present($item);
    }

    /** @return array<string, mixed> */
    public function duplicate(Client $actor, int $itemId): array
    {
        $item = $this->item($actor, $itemId);
        AgendaItem::query()->where('event_id', $item->event_id)->where('sort_order', '>', $item->sort_order)->increment('sort_order');
        $copy = $item->replicate();
        $copy->title = mb_substr($item->title, 0, 93).' (Copy)';
        $copy->sort_order = $item->sort_order + 1;
        $copy->save();

        return $this->present($copy);
    }

    /** @return array{removed: true} */
    public function delete(Client $actor, int $itemId): array
    {
        $item = $this->item($actor, $itemId);
        $images = $item->images ?? [];
        $item->delete();
        if ($images !== []) {
            $inUse = AgendaItem::query()->where('event_id', $item->event_id)->get(['images'])->flatMap(static fn (AgendaItem $i): array => $i->images ?? [])->all();
            $this->forget(array_diff($images, $inUse));
        }

        return ['removed' => true];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function reorder(Client $actor, int $eventId, mixed $ids): array
    {
        $event = $this->managed($actor, $eventId);
        if (! is_array($ids)) {
            throw ApiException::badRequest('Invalid agenda order.');
        }
        Ordering::apply($this->ordered($event), $ids);

        return ['items' => $this->items($event)];
    }

    /** @return array{url: string} */
    public function upload(Client $actor, int $eventId, ?UploadedFile $file): array
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose an image to upload.');
        }
        $event = $this->managed($actor, $eventId);
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ApiException::badRequest('Please choose a JPG, PNG or WEBP image.');
        }
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw ApiException::badRequest('That image is larger than 2MB.');
        }

        return ['url' => $this->storage->upload($file, 'event-agenda/'.$event->id)['url']];
    }

    /** @return Builder<AgendaItem> */
    private function ordered(Event $event): Builder
    {
        return AgendaItem::query()->where('event_id', $event->id)->orderBy('sort_order')->orderBy('agenda_date')->orderBy('start_time')->orderBy('id');
    }

    /** @return list<array<string, mixed>> */
    private function items(Event $event): array
    {
        return $this->ordered($event)->get()->map(fn (AgendaItem $i): array => $this->present($i))->all();
    }

    /** @return array<string, mixed> */
    private function present(AgendaItem $i): array
    {
        return [
            'id' => $i->id, 'event_id' => $i->event_id, 'title' => $i->title, 'description' => $i->description,
            'agenda_date' => $i->agenda_date->format('Y-m-d'), 'end_date' => $i->end_date?->format('Y-m-d'),
            'start_time' => substr($i->start_time, 0, 5), 'end_time' => substr($i->end_time, 0, 5),
            'location' => $i->location, 'images' => array_values(array_filter($i->images ?? [], 'is_string')), 'sort_order' => $i->sort_order,
        ];
    }

    /** @return list<string> the event's days, for the day tabs (at most 60) */
    private function days(Event $event): array
    {
        if ($event->start_date === null) {
            return [];
        }
        $days = [];
        $end = $event->end_date ?? $event->start_date;
        for ($d = $event->start_date->copy(); $d->lte($end) && count($days) < 60; $d->addDay()) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalise(array $input, Event $event, ?AgendaItem $current): array
    {
        $partial = $current !== null;
        $has = static fn (string $f): bool => array_key_exists($f, $input);
        $str = static fn (mixed $v, int $max): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $v = [];

        if (! $partial || $has('title')) {
            $v['title'] = $str($input['title'] ?? null, 100) ?? throw ApiException::badRequest('Please fill all mandatory fields.');
        }
        if ($has('description')) {
            $v['description'] = $str($input['description'], 200);
        }
        if ($has('location')) {
            $v['location'] = $str($input['location'], 150);
        }
        foreach (['agenda_date', 'start_time', 'end_time'] as $field) {
            if (! $partial || $has($field)) {
                $v[$field] = $str($input[$field] ?? null, 10) ?? throw ApiException::badRequest('Please fill all mandatory fields.');
            }
        }
        if ($has('end_date')) {
            $v['end_date'] = $str($input['end_date'], 10);
        }
        if ($has('images')) {
            if ($input['images'] !== null && ! is_array($input['images'])) {
                throw ApiException::badRequest('Invalid agenda images.');
            }
            $list = array_values(array_unique(array_filter($input['images'] ?? [], 'is_string')));
            foreach ($list as $url) {
                if (preg_match('#^(https?://|/uploads/)#i', $url) !== 1) {
                    throw ApiException::badRequest('Invalid agenda image.');
                }
            }
            if (count($list) > self::MAX_IMAGES) {
                throw ApiException::badRequest('An agenda item can have up to '.self::MAX_IMAGES.' images.');
            }
            $v['images'] = $list;
        }

        $date = $v['agenda_date'] ?? $current?->agenda_date->format('Y-m-d');
        $endDate = array_key_exists('end_date', $v) ? $v['end_date'] : $current?->end_date?->format('Y-m-d');
        $start = $v['start_time'] ?? $current?->start_time;
        $end = $v['end_time'] ?? $current?->end_time;
        $isDate = static fn (?string $d): bool => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        $isTime = static fn (?string $t): bool => $t !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t) === 1;
        if (! $isDate($date)) {
            throw ApiException::badRequest('Please choose a valid date.');
        }
        if ($endDate !== null && ! $isDate($endDate)) {
            throw ApiException::badRequest('Please choose a valid end date.');
        }
        if ($endDate !== null && $endDate <= $date) {
            throw ApiException::badRequest('The end date must be after the start date.');
        }
        if (! $isTime($start) || ! $isTime($end)) {
            throw ApiException::badRequest('Please choose a valid time.');
        }
        if ($endDate === null && substr((string) $start, 0, 5) === substr((string) $end, 0, 5)) {
            throw ApiException::badRequest('The end time must be different from the start time.');
        }
        $first = $event->start_date?->format('Y-m-d');
        $last = ($event->end_date ?? $event->start_date)?->format('Y-m-d');
        if (($first !== null && $date < $first) || ($last !== null && ($endDate ?? $date) > $last)) {
            throw ApiException::badRequest('The agenda date must be within the event dates.');
        }

        return $v;
    }

    private function managed(Client $actor, int $eventId): Event
    {
        $event = Event::query()->with('client')->find($eventId);
        if ($event === null || ! $this->access->canManage($actor, $event)) {
            throw ApiException::notFound('That event is not on your account.');
        }

        return $event;
    }

    private function item(Client $actor, int $itemId): AgendaItem
    {
        $item = AgendaItem::query()->whereIn('event_id', $this->access->managedEventIds($actor))->find($itemId);

        return $item ?? throw ApiException::notFound('That agenda item was not found.');
    }

    /** @param array<int, string> $urls */
    private function forget(array $urls): void
    {
        foreach ($urls as $url) {
            $this->storage->deleteByUrl($url);
        }
    }
}
