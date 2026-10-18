<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\EventQr;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventMenuChoice;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\PlanEntitlements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creating and editing events (v1 clientEvent.service normalise/create/update).
 *
 * Every write is checked against the plan of the client who owns the event:
 * the category, each menu and the number of events must be within it. Default
 * menus are always switched on. The QR code is issued once, at creation, and
 * names only the event id (encrypted), so it never changes.
 */
final class EventWriter
{
    private const COMPONENT_KEYS = [
        'event_title', 'host_names', 'date_time', 'venue', 'event_qr_code', 'organizer', 'event_photos', 'contact_details',
        'invitation_message', 'footer_note', 'decoration_elements',
    ];

    /** field => max length, for the plain text fields. */
    private const TEXT = [
        'tagline' => 150, 'description' => 5000, 'venue_name' => 255, 'venue_address' => 500, 'venue_landmark' => 255,
        'ceremony_title' => 150, 'ceremony_venue' => 255, 'ceremony_description' => 2000, 'timezone' => 80, 'host_one' => 120,
        'host_two' => 120, 'organizer' => 200, 'footer_note' => 300,
    ];

    private const IMAGES = ['venue_image' => 'Invalid venue image.', 'cover_image' => 'Invalid event image.', 'organizer_image' => 'Invalid organizer image.', 'custom_image' => 'Invalid custom image.'];

    public function __construct(
        private readonly PlanEntitlements $plans,
        private readonly EventAccess $access,
        private readonly EventQr $qr,
        private readonly Organizers $organizers,
        private readonly ObjectStorage $storage,
        private readonly Gallery $gallery,
        private readonly Wishes $wishes,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(Client $actor, array $input): Event
    {
        if ($actor->plan_id === null && $this->access->organised($actor) !== []) {
            throw ApiException::forbidden('Only the client can create events.');
        }
        [$values, $menus, $plan] = $this->normalise($actor, $input, partial: false);

        $max = $this->plans->limit($plan, 'max_events');
        if ($max !== null && Event::query()->withTrashed()->where('client_id', $actor->id)->count() >= $max) {
            throw ApiException::badRequest("Your plan allows a maximum of {$max} event".($max === 1 ? '' : 's').'. Please upgrade your plan to create more.');
        }

        $event = DB::transaction(function () use ($actor, $values, $menus, $plan): Event {
            $event = Event::query()->create([...$values, 'client_id' => $actor->id, 'vendor_id' => $actor->vendor_id, 'plan_id' => $plan->id]);
            $this->syncMenus($event, $menus);
            $issued = now()->startOfSecond();
            $event->forceFill(['qr_token' => $this->qr->issue($event->id, $issued), 'qr_version' => EventQr::VERSION, 'qr_issued_at' => $issued])->save();

            return $event;
        });
        if ($event->contact_phone !== null) {
            $this->organizers->syncEvent($event);
        }

        return $event;
    }

    /** @param array<string, mixed> $input */
    public function update(Client $owner, Event $event, array $input): Event
    {
        [$values, $menus] = $this->normalise($owner, $input, partial: true);

        DB::transaction(function () use ($event, $values, $menus): void {
            $event->fill($values)->save();
            $this->syncMenus($event, $menus);
            if ($event->qr_token === null) {
                $issued = now()->startOfSecond();
                $event->forceFill(['qr_token' => $this->qr->issue($event->id, $issued), 'qr_version' => EventQr::VERSION, 'qr_issued_at' => $issued])->save();
            }
        });
        if (array_key_exists('contact_phone', $values)) {
            $this->organizers->syncEvent($event);
        }

        return $event;
    }

    /** The event goes; its gallery and wish files go with it (v1 purgeEvent). */
    public function delete(Event $event): void
    {
        $event->delete();
        $this->gallery->purge($event->id);
        $this->wishes->purge($event->id);
    }

    /** @return array{url: string} */
    public function uploadCover(?UploadedFile $file): array
    {
        $this->assertImage($file, ['image/jpeg', 'image/png', 'image/webp'], 5, 'Please choose a JPG, PNG or WEBP image.', 'That image is larger than 5MB.');

        return ['url' => $this->storage->upload($file, 'event-covers')['url']];
    }

    /** @return array{url: string} */
    public function uploadInvitation(Event $event, ?UploadedFile $file): array
    {
        $this->assertImage($file, ['image/png', 'image/jpeg', 'image/webp'], 10, 'The invitation must be a PNG, JPG or WEBP image.', 'The invitation image is larger than 10MB.');
        $url = $this->storage->upload($file, 'event-invitations')['url'];
        $event->forceFill(['invitation_image' => $url])->save();

        return ['url' => $url];
    }

    /**
     * @param  list<string>  $types
     *
     * @phpstan-assert UploadedFile $file
     */
    private function assertImage(?UploadedFile $file, array $types, int $megabytes, string $typeMessage, string $sizeMessage): void
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose an image to upload.');
        }
        if (! in_array($file->getMimeType(), $types, true)) {
            throw ApiException::badRequest($typeMessage);
        }
        if ($file->getSize() > $megabytes * 1024 * 1024) {
            throw ApiException::badRequest($sizeMessage);
        }
    }

    /**
     * v1 normalise(): validate and clean the input against the owner's plan.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array{ids: list<int>|null, order: list<int>|null, touched_order: bool}, 2: Plan}
     */
    private function normalise(Client $owner, array $input, bool $partial): array
    {
        [$plan, $reason] = $this->plans->planOf($owner);
        if ($plan === null || $reason !== null) {
            throw ApiException::badRequest($reason ?? 'No subscription plan is assigned to your account yet.');
        }
        $options = $this->plans->options($owner);
        $has = static fn (string $f): bool => array_key_exists($f, $input);
        $required = static fn (string $f): bool => ! $partial || array_key_exists($f, $input);
        $v = [];

        if ($required('event_category_id')) {
            $id = is_numeric($input['event_category_id'] ?? null) ? (int) $input['event_category_id'] : 0;
            if ($id === 0) {
                throw ApiException::badRequest('Please select an event category.');
            }
            if (! in_array($id, array_column($options['categories'], 'id'), true)) {
                throw ApiException::badRequest('That event category is not included in your subscription plan.');
            }
            $v['event_category_id'] = $id;
        }
        if ($required('name')) {
            $v['name'] = $this->str($input['name'] ?? null, 200) ?? throw ApiException::badRequest('Please enter an event name.');
        }
        foreach (self::TEXT as $field => $max) {
            if ($has($field)) {
                $v[$field] = $this->str($input[$field], $max);
            }
        }
        if ($has('venue_map_link')) {
            $v['venue_map_link'] = $this->str($input['venue_map_link'], 500);
            if ($v['venue_map_link'] !== null && preg_match('#^https?://#i', $v['venue_map_link']) !== 1) {
                throw ApiException::badRequest('Please enter a valid map link starting with http:// or https://.');
            }
        }
        foreach (self::IMAGES as $field => $message) {
            if ($has($field)) {
                $v[$field] = $this->str($input[$field], 500);
                if ($v[$field] !== null && preg_match('#^(https?://|/uploads/)#i', $v[$field]) !== 1) {
                    throw ApiException::badRequest($message);
                }
            }
        }
        if ($has('venue_lat') || $has('venue_lng')) {
            $blank = static fn ($x): bool => $x === null || $x === '';
            if ($blank($input['venue_lat'] ?? null) && $blank($input['venue_lng'] ?? null)) {
                $v['venue_lat'] = $v['venue_lng'] = null;
            } else {
                $lat = $input['venue_lat'] ?? null;
                $lng = $input['venue_lng'] ?? null;
                if (! is_numeric($lat) || ! is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    throw ApiException::badRequest('Invalid map location.');
                }
                $v['venue_lat'] = round((float) $lat, 7);
                $v['venue_lng'] = round((float) $lng, 7);
            }
        }
        if ($has('contact_phone')) {
            $v['contact_phone'] = $this->str($input['contact_phone'], 30);
            if ($v['contact_phone'] !== null && preg_match('/^[\d\s+()-]{6,30}$/', $v['contact_phone']) !== 1) {
                throw ApiException::badRequest('Please enter a valid contact number.');
            }
        }
        if ($has('contact_email')) {
            $v['contact_email'] = $this->str($input['contact_email'], 150);
            if ($v['contact_email'] !== null && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v['contact_email']) !== 1) {
                throw ApiException::badRequest('Please enter a valid contact email address.');
            }
        }
        foreach (['start_date', 'end_date'] as $field) {
            if ($required($field)) {
                $value = $this->str($input[$field] ?? null, 10) ?? throw ApiException::badRequest('Please fill in the event start and end dates.');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                    throw ApiException::badRequest('Dates must be in YYYY-MM-DD format.');
                }
                $v[$field] = $value;
            }
        }
        foreach (['start_time', 'end_time'] as $field) {
            if ($required($field)) {
                $value = $this->str($input[$field] ?? null, 8) ?? throw ApiException::badRequest('Please fill in the event start and end times.');
                if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) !== 1) {
                    throw ApiException::badRequest('Times must be in HH:MM format.');
                }
                $v[$field] = strlen($value) === 5 ? $value.':00' : $value;
            }
        }
        if (isset($v['start_date'], $v['end_date']) && $v['end_date'] < $v['start_date']) {
            throw ApiException::badRequest('The end date cannot be before the start date.');
        }
        if ($has('privacy')) {
            $v['privacy'] = $this->oneOf($input['privacy'], ['private', 'public', 'unlisted'], 'Invalid event privacy.');
        }
        if ($has('status')) {
            $v['status'] = $this->oneOf($input['status'], ['draft', 'upcoming', 'cancelled'], 'Invalid event status.');
        }
        if ($has('theme_id')) {
            $v['theme_id'] = $this->str($input['theme_id'], 64);
            if ($v['theme_id'] !== null && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $v['theme_id']) !== 1) {
                throw ApiException::badRequest('Invalid theme.');
            }
        }
        foreach (['primary_color' => 'Primary colour must be a hex value like #2457D6.', 'secondary_color' => 'Secondary colour must be a hex value like #F59E0B.'] as $field => $message) {
            if ($has($field)) {
                $v[$field] = $this->str($input[$field], 9);
                if ($v[$field] !== null && preg_match('/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v[$field]) !== 1) {
                    throw ApiException::badRequest($message);
                }
            }
        }
        if ($has('components')) {
            $raw = $input['components'];
            if ($raw === null || $raw === '') {
                $v['components'] = null;
            } elseif (! is_array($raw) || array_is_list($raw)) {
                throw ApiException::badRequest('Invalid invitation component settings.');
            } else {
                $v['components'] = [];
                foreach (self::COMPONENT_KEYS as $key) {
                    $v['components'][$key] = ! array_key_exists($key, $raw) || (bool) (int) $raw[$key] ? 1 : 0;
                }
            }
        }
        if ($has('component_order')) {
            $raw = $input['component_order'];
            if ($raw === null || $raw === '') {
                $v['component_order'] = null;
            } elseif (! is_array($raw)) {
                throw ApiException::badRequest('Invalid invitation component order.');
            } else {
                $seen = array_values(array_unique(array_filter($raw, static fn ($k): bool => in_array($k, self::COMPONENT_KEYS, true))));
                $v['component_order'] = [...$seen, ...array_values(array_diff(self::COMPONENT_KEYS, $seen))];
            }
        }
        if ($has('qr_style')) {
            if (! in_array((int) $input['qr_style'], [0, 1, 2], true) || ! is_numeric($input['qr_style'])) {
                throw ApiException::badRequest('Invalid QR code style.');
            }
            $v['qr_style'] = (int) $input['qr_style'];
        }

        return [$v, $this->menus($input, $options['menus'], $v['event_category_id'] ?? null), $plan];
    }

    /**
     * menu_ids within the plan and category, defaults added; menu_order kept to granted menus.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $granted
     * @return array{ids: list<int>|null, order: list<int>|null, touched_order: bool}
     */
    private function menus(array $input, array $granted, ?int $categoryId): array
    {
        $byId = [];
        foreach ($granted as $menu) {
            $byId[(int) $menu['id']] = $menu;
        }
        $ids = null;
        if (array_key_exists('menu_ids', $input)) {
            $raw = is_array($input['menu_ids']) ? $input['menu_ids'] : [];
            $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $n): bool => $n > 0)));
            if (array_diff($ids, array_keys($byId)) !== []) {
                throw ApiException::badRequest('One or more selected menus are not included in your subscription plan.');
            }
            if ($categoryId !== null) {
                foreach ($ids as $id) {
                    $menuCategory = $byId[$id]['event_category_id'];
                    if ($menuCategory !== null && (int) $menuCategory !== $categoryId) {
                        throw ApiException::badRequest('One or more selected menus do not match the event category.');
                    }
                }
            }
            $defaults = array_keys(array_filter($byId, static fn (array $m): bool => (int) $m['is_default'] === 1
                && ($categoryId === null || $m['event_category_id'] === null || (int) $m['event_category_id'] === $categoryId)));
            $ids = array_values(array_unique([...$ids, ...$defaults]));
        }

        $order = null;
        $touched = array_key_exists('menu_order', $input);
        if ($touched) {
            $raw = $input['menu_order'];
            if ($raw !== null && $raw !== '' && ! is_array($raw)) {
                throw ApiException::badRequest('Invalid menu order.');
            }
            $order = is_array($raw) ? array_values(array_filter(array_unique(array_map('intval', $raw)), static fn (int $id): bool => isset($byId[$id]))) : null;
        }

        return ['ids' => $ids, 'order' => $order, 'touched_order' => $touched];
    }

    /** @param array{ids: list<int>|null, order: list<int>|null, touched_order: bool} $menus */
    private function syncMenus(Event $event, array $menus): void
    {
        if ($menus['ids'] === null && ! $menus['touched_order']) {
            return;
        }
        $existing = EventMenuChoice::query()->where('event_id', $event->id)->get()->keyBy('event_menu_id');
        $selected = $menus['ids'] ?? $existing->where('selected', true)->keys()->map(static fn ($k): int => (int) $k)->all();
        $order = $menus['touched_order'] ? ($menus['order'] ?? []) : $existing->whereNotNull('position')->sortBy('position')->keys()->map(static fn ($k): int => (int) $k)->all();

        $rows = [];
        foreach (array_unique([...$selected, ...$order]) as $menuId) {
            $position = array_search($menuId, $order, true);
            $rows[] = ['event_id' => $event->id, 'event_menu_id' => $menuId, 'selected' => in_array($menuId, $selected, true), 'position' => $position === false ? null : $position];
        }
        EventMenuChoice::query()->where('event_id', $event->id)->delete();
        if ($rows !== []) {
            EventMenuChoice::query()->insert($rows);
        }
    }

    private function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $message): string
    {
        $value = mb_strtolower(is_scalar($value) ? (string) $value : '');

        return in_array($value, $allowed, true) ? $value : throw ApiException::badRequest($message);
    }
}
