<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\EventQr;
use App\Core\Tenancy\Tenancy;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientFavourite;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Models\WishBlock;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\PlanEntitlements;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Models\EventTemplate;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Reading events in the v1 shapes (clientEvent.service present / list / stats
 * / analytics / wishlist).
 *
 * An event shows the menus its host switched on plus every Default menu,
 * narrowed to what the host's plan grants today, in the host's order. A guest
 * never sees the host-only menus or fields.
 */
final class EventReader
{
    private const HOST_ONLY_FIELDS = ['website_client_id', 'subscription_plan_id', 'plan', 'qr_token', 'qr_issued_at'];

    private const HOST_ONLY_MENUS = ['guests'];

    private const DESIGN_COLUMNS = [
        'id', 'code', 'name', 'orientation', 'layout_style', 'background_type', 'background_color', 'background_image',
        'gradient_from', 'gradient_via', 'gradient_to', 'gradient_type', 'gradient_direction', 'overlay_enabled',
        'overlay_color', 'overlay_opacity', 'secondary_color', 'primary_font', 'secondary_font', 'border_style',
        'primary_font_size', 'secondary_font_size', 'frame_color', 'decoration_color', 'image_shape', 'corner_radius',
        'frame_style_id', 'components', 'component_order',
    ];

    /** @var Closure(list<int>): array<int, int>|null Messaging: invitations sent per event */
    private static ?Closure $invitationsSent = null;

    public function __construct(
        private readonly EventAccess $access,
        private readonly PlanEntitlements $plans,
        private readonly EventQr $qr,
    ) {}

    /** @param Closure(list<int>): array<int, int> $counter */
    public static function countInvitationsUsing(Closure $counter): void
    {
        self::$invitationsSent = $counter;
    }

    /** @return array<string, mixed> one event as its owner (or organizer) reads it */
    public function forManager(Event $event): array
    {
        return $this->present($event, isOwner: true, viewer: null);
    }

    /** @return array<string, mixed> one event for whoever asks: owner, organizer or participant */
    public function forViewer(Client $viewer, int $eventId): array
    {
        $event = Event::query()->with(['plan', 'category', 'client', 'settings', 'menuChoices'])->find($eventId)
            ?? throw ApiException::notFound('Event not found.');
        $manages = $this->access->canManage($viewer, $event);
        if (! $manages && ! $this->access->isParticipant($viewer, $event->id)) {
            throw ApiException::notFound('Event not found.');
        }

        $data = $this->present($event, isOwner: $manages, viewer: $viewer);
        $data['is_owner'] = $event->client_id === $viewer->id;
        if ($this->access->isOrganizer($viewer, $event)) {
            $data['is_organizer'] = true;
        }

        return $data;
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, int>} */
    public function list(Client $owner, Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));
        $limit = min(100, max(1, (int) $request->query('limit', '12')));
        $status = mb_strtolower((string) $request->query('status', 'all'));
        $search = trim((string) $request->query('search', ''));
        $category = (int) $request->query('category_id', '0');
        $privacy = mb_strtolower((string) $request->query('privacy', ''));
        $today = today()->format('Y-m-d');

        $query = Event::query()->where('client_id', $owner->id)->with(['plan:id,name,plan_code', 'category:id,name,color,icon', 'settings', 'menuChoices', 'client'])
            ->when(in_array($status, ['draft', 'cancelled'], true), static fn ($q) => $q->where('status', $status))
            ->when($status === 'published', static fn ($q) => $q->where('status', 'upcoming'))
            ->when(in_array($status, ['past', 'live', 'upcoming'], true), static function (Builder $q) use ($status, $today): void {
                $q->where('status', 'upcoming');
                $end = 'coalesce(end_date, start_date)';
                $start = 'coalesce(start_date, end_date)';
                match ($status) {
                    'past' => $q->whereRaw("{$end} < ?", [$today]),
                    'live' => $q->whereRaw("{$end} >= ?", [$today])->whereRaw("{$start} <= ?", [$today]),
                    default => $q->where(static fn ($w) => $w->whereRaw("{$start} is null")->orWhereRaw("{$start} > ?", [$today])),
                };
            })
            ->when($category > 0, static fn ($q) => $q->where('event_category_id', $category))
            ->when(in_array($privacy, ['private', 'public', 'unlisted'], true), static fn ($q) => $q->where('privacy', $privacy))
            ->when($search !== '', static fn ($q) => $q->where(static fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('tagline', 'like', "%{$search}%")->orWhere('venue_name', 'like', "%{$search}%")));

        [$column, $direction] = match ((string) $request->query('sort', '')) {
            'date_asc' => ['start_date', 'asc'],
            'date_desc' => ['start_date', 'desc'],
            'name_asc' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            'created_desc' => ['created_at', 'desc'],
            default => in_array($status, ['upcoming', 'live'], true) ? ['start_date', 'asc'] : ['start_date', 'desc'],
        };
        $total = (clone $query)->count();
        $events = $query->orderBy($column, $direction)->orderBy('id', $direction)->forPage($page, $limit)->get();

        $counts = $this->participantCounts($events->pluck('id')->all());
        $rows = $events->map(function (Event $e) use ($counts): array {
            $row = $this->present($e, isOwner: true, viewer: null, withStats: false);
            $c = $counts[$e->id] ?? ['guests' => 0, 'confirmed' => 0, 'pending' => 0, 'invited' => 0];

            return [...$row, 'guest_count' => $c['guests'], 'confirmed_count' => $c['confirmed'], 'pending_count' => $c['pending'], 'messages_count' => $c['invited']];
        })->all();

        // Events this client organises for somebody else go on the first page, marked.
        if ($page === 1) {
            foreach (array_keys($this->access->organised($owner)) as $eventId) {
                $event = Event::query()->with(['plan', 'category', 'client', 'settings', 'menuChoices'])->find($eventId);
                if ($event !== null && $this->access->canManage($owner, $event)) {
                    $rows[] = [...$this->present($event, isOwner: true, viewer: null), 'is_organizer' => true];
                    $total++;
                }
            }
        }

        return [$rows, ['page' => $page, 'limit' => $limit, 'totalItems' => $total, 'totalPages' => max(1, (int) ceil($total / $limit))]];
    }

    /** @return array<string, mixed> the dashboard counters */
    public function stats(Client $owner): array
    {
        $events = Event::query()->where('client_id', $owner->id)->get(['id', 'status', 'start_date', 'end_date']);
        $counts = ['upcoming' => 0, 'live' => 0, 'past' => 0, 'draft' => 0, 'cancelled' => 0];
        $soon = 0;
        $horizon = today()->addDays(30)->format('Y-m-d');
        foreach ($events as $event) {
            $derived = $event->derivedStatus();
            $counts[$derived]++;
            if ($derived === 'upcoming' && $event->start_date !== null && $event->start_date->format('Y-m-d') <= $horizon) {
                $soon++;
            }
        }

        return [
            'total_events' => $events->count(),
            'published_events' => $counts['live'] + $counts['upcoming'] + $counts['past'],
            'live_events' => $counts['live'],
            'upcoming_events' => $counts['upcoming'],
            'past_events' => $counts['past'],
            'draft_events' => $counts['draft'],
            'cancelled_events' => $counts['cancelled'],
            'upcoming_next_30_days' => $soon,
            'total_guests' => 0,
            'rsvps_received' => 0,
            'rsvp_going' => 0,
            'rsvp_pending' => 0,
            'rsvp_declined' => 0,
            'guests_available' => false,
        ];
    }

    /** @return array<string, mixed> the event side of the analytics screen */
    public function analytics(Client $owner, int $months = 6): array
    {
        $months = min(24, max(3, $months));
        $events = Event::query()->where('client_id', $owner->id)->with(['category:id,name,color', 'menuChoices'])
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get(['id', 'name', 'status', 'start_date', 'end_date', 'created_at', 'privacy', 'theme_id', 'event_category_id']);

        $byStatus = ['live' => 0, 'upcoming' => 0, 'past' => 0, 'draft' => 0, 'cancelled' => 0];
        $byPrivacy = ['private' => 0, 'public' => 0, 'unlisted' => 0];
        $categories = [];
        $themes = [];
        $menus = [];
        foreach ($events as $event) {
            $byStatus[$event->derivedStatus()]++;
            $byPrivacy[$event->privacy]++;
            $name = $event->category->name ?? 'Uncategorised';
            $categories[$name] ??= ['name' => $name, 'color' => $event->category?->getAttribute('color'), 'count' => 0];
            $categories[$name]['count']++;
            $theme = $event->theme_id ?? 'unset';
            $themes[$theme] = ($themes[$theme] ?? 0) + 1;
            foreach ($event->menuChoices->where('selected', true) as $choice) {
                $menus[$choice->event_menu_id] = ($menus[$choice->event_menu_id] ?? 0) + 1;
            }
        }

        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $d = now()->startOfMonth()->subMonthsNoOverflow($i);
            $buckets[$d->format('Y-m')] = ['key' => $d->format('Y-m'), 'label' => $d->format('M'), 'year' => (int) $d->format('Y'), 'created' => 0, 'scheduled' => 0];
        }
        $periodStart = now()->startOfMonth()->subMonthsNoOverflow($months - 1);
        $previousStart = now()->startOfMonth()->subMonthsNoOverflow($months * 2 - 1);
        $createdNow = 0;
        $createdBefore = 0;
        foreach ($events as $event) {
            if ($event->created_at !== null) {
                $key = $event->created_at->format('Y-m');
                if (isset($buckets[$key])) {
                    $buckets[$key]['created']++;
                }
                if ($event->created_at->gte($periodStart)) {
                    $createdNow++;
                } elseif ($event->created_at->gte($previousStart)) {
                    $createdBefore++;
                }
            }
            if ($event->start_date !== null && isset($buckets[$event->start_date->format('Y-m')])) {
                $buckets[$event->start_date->format('Y-m')]['scheduled']++;
            }
        }
        $busiest = collect($buckets)->sortByDesc('scheduled')->first();
        $published = $byStatus['live'] + $byStatus['upcoming'] + $byStatus['past'];
        $menuNames = EventMenu::query()->withoutGlobalScopes()->whereIn('id', array_keys($menus))->pluck('name', 'id');
        arsort($menus);
        arsort($themes);

        return [
            'period' => [
                'months' => $months,
                'created_this_period' => $createdNow,
                'created_previous_period' => $createdBefore,
                'created_change_pct' => $createdBefore === 0 ? null : round(($createdNow - $createdBefore) / $createdBefore * 100, 1),
                'busiest_month' => $busiest !== null && $busiest['scheduled'] > 0 ? ['label' => $busiest['label'], 'year' => $busiest['year'], 'count' => $busiest['scheduled']] : null,
            ],
            'totals' => [
                'total_events' => $events->count(),
                'published_events' => $published,
                'live_events' => $byStatus['live'],
                'upcoming_events' => $byStatus['upcoming'],
                'past_events' => $byStatus['past'],
                'draft_events' => $byStatus['draft'],
                'cancelled_events' => $byStatus['cancelled'],
                'completion_rate' => $published > 0 ? round($byStatus['past'] / $published * 100, 1) : 0,
            ],
            'by_status' => array_map(static fn (string $k, int $n): array => ['key' => $k, 'count' => $n], array_keys($byStatus), $byStatus),
            'by_privacy' => array_map(static fn (string $k, int $n): array => ['key' => $k, 'count' => $n], array_keys($byPrivacy), $byPrivacy),
            'by_category' => collect($categories)->sortByDesc('count')->values()->all(),
            'by_theme' => array_map(static fn ($t, int $n): array => ['theme_id' => (string) $t, 'count' => $n], array_keys($themes), $themes),
            'top_menus' => array_slice(array_map(static fn ($id, int $n): array => ['id' => (int) $id, 'name' => $menuNames[$id] ?? "Menu #{$id}", 'count' => $n], array_keys($menus), $menus), 0, 6),
            'timeline' => array_values($buckets),
            'recent_events' => $events->take(5)->map(static fn (Event $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'start_date' => $e->start_date?->format('Y-m-d'),
                'theme_id' => $e->theme_id,
                'derived_status' => $e->derivedStatus(),
                'category' => $e->category?->name,
                'menu_count' => $e->menuChoices->where('selected', true)->count(),
            ])->values()->all(),
        ];
    }

    /**
     * POST /events/qr/decode — the code's payload, and the live event it names.
     *
     * @return array<string, mixed>
     */
    public function decodeQr(mixed $token): array
    {
        if (! is_string($token) || $token === '') {
            throw ApiException::badRequest('No QR token supplied.');
        }
        $payload = $this->qr->decrypt($token) ?? throw ApiException::badRequest('This QR code is not valid.');
        $expanded = EventQr::expand($payload);
        $event = is_numeric($expanded['event_id']) ? Tenancy::bypass(static fn () => Event::query()->with(['plan', 'category', 'client', 'settings', 'menuChoices'])->find((int) $expanded['event_id'])) : null;

        return ['payload' => $expanded, 'event' => $event !== null ? $this->present($event, isOwner: true, viewer: null, withStats: false, withMenus: false) : null];
    }

    /** @return list<array<string, mixed>> the client's starred events they may still see */
    public function wishlist(Client $viewer): array
    {
        $ids = ClientFavourite::query()->where('client_id', $viewer->id)->where('kind', 'event')->orderBy('sort_order')->pluck('ref')->map(static fn ($r): int => (int) $r)->all();
        if ($ids === []) {
            return [];
        }
        $events = Event::query()->whereIn('id', $ids)->with(['plan', 'category', 'client', 'settings', 'menuChoices'])->get()->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            $event = $events->get($id);
            if ($event === null) {
                continue;
            }
            $manages = $this->access->canManage($viewer, $event);
            if (! $manages && ! $this->access->isParticipant($viewer, $id)) {
                continue;
            }
            $rows[] = [...$this->present($event, isOwner: $manages, viewer: $viewer), 'wishlisted' => true];
        }

        return $rows;
    }

    /** @return array{event_id: int, wishlisted: bool, count: int} */
    public function setWishlisted(Client $viewer, int $eventId, bool $on): array
    {
        $current = ClientFavourite::query()->where('client_id', $viewer->id)->where('kind', 'event');
        if ($on) {
            $event = Event::query()->with('client')->find($eventId);
            if ($event === null || (! $this->access->canManage($viewer, $event) && ! $this->access->isParticipant($viewer, $eventId))) {
                throw ApiException::notFound('Event not found');
            }
            if (! (clone $current)->where('ref', (string) $eventId)->exists() && (clone $current)->count() < 500) {
                ClientFavourite::query()->create(['client_id' => $viewer->id, 'kind' => 'event', 'ref' => (string) $eventId, 'sort_order' => (clone $current)->max('sort_order') + 1]);
            }
        } else {
            (clone $current)->where('ref', (string) $eventId)->delete();
        }

        return ['event_id' => $eventId, 'wishlisted' => $on, 'count' => (clone $current)->count()];
    }

    /** @return array<string, mixed> */
    private function present(Event $event, bool $isOwner, ?Client $viewer, bool $withStats = true, bool $withMenus = true): array
    {
        $event->loadMissing(['plan', 'category', 'client', 'settings', 'menuChoices']);
        $choices = $event->menuChoices;
        $selected = $choices->where('selected', true)->pluck('event_menu_id')->map(static fn ($id): int => (int) $id)->values()->all();
        $order = $choices->whereNotNull('position')->sortBy('position')->pluck('event_menu_id')->map(static fn ($id): int => (int) $id)->values()->all();
        $settings = $event->settings;

        $data = [
            ...$event->attributesToArray(),
            'website_client_id' => $event->client_id,
            'subscription_plan_id' => $event->plan_id,
            'company_id' => $event->client->tenantCompanyId(),
            'qr_token' => $event->qr_token,
            'menu_ids' => $selected,
            'menu_order' => $order === [] ? null : $order,
            'disabled_app_menu_ids' => [],
            'rsvp_settings' => $settings?->rsvp,
            'participant_settings' => $settings?->participant,
            'family_settings' => $settings?->family,
            'wish_settings' => $settings?->wish,
            'derived_status' => $event->derivedStatus(),
            'plan' => $event->plan?->only(['id', 'name', 'plan_code']),
            'category' => $event->category?->only(['id', 'name', 'color', 'icon']),
            'design' => $this->design($event),
        ];
        unset($data['client_id'], $data['plan_id']);

        if ($withMenus) {
            $data['menus'] = $this->menus($event, $selected, $order, $isOwner, $viewer);
        }
        if ($withStats) {
            $data['stats'] = $this->guestStats($event->id);
        }
        if (! $isOwner) {
            foreach (self::HOST_ONLY_FIELDS as $field) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    /**
     * @param  list<int>  $selected
     * @param  list<int>  $order
     * @return list<array<string, mixed>>
     */
    private function menus(Event $event, array $selected, array $order, bool $isOwner, ?Client $viewer): array
    {
        [$plan, $reason] = $this->plans->planOf($event->client);
        if ($plan === null || $reason !== null) {
            return [];
        }
        $category = $event->event_category_id;
        $hideWishes = ! $isOwner && $viewer !== null
            && WishBlock::query()->where('event_id', $event->id)->where('author_client_id', $viewer->id)->where('hide_menu', true)->exists();

        $menus = collect($this->plans->grantedMenus($plan))
            ->filter(static fn (array $m): bool => in_array((int) $m['id'], $selected, true)
                || ((int) $m['is_default'] === 1 && ($m['event_category_id'] === null || $category === null || (int) $m['event_category_id'] === $category)))
            ->reject(static fn (array $m): bool => ! $isOwner && in_array($m['slug'], self::HOST_ONLY_MENUS, true))
            ->reject(static fn (array $m): bool => $hideWishes && $m['slug'] === 'wishes')
            ->map(static fn (array $m): array => [
                'id' => $m['id'], 'name' => $m['name'], 'slug' => $m['slug'], 'icon' => $m['icon'], 'color' => $m['color'],
                'sort_order' => $m['sort_order'], 'is_default' => (int) $m['is_default'] === 1,
            ])
            ->values();

        if ($order !== []) {
            $rank = static fn (array $m): int => ($i = array_search((int) $m['id'], $order, true)) === false ? count($order) : $i;
            $menus = $menus->sortBy($rank)->values();
        }

        return $menus->all();
    }

    /** @return array<string, mixed>|null the invitation template behind theme_id, with its artwork */
    private function design(Event $event): ?array
    {
        return $this->designFor($event->theme_id, $event->client->tenantCompanyId());
    }

    /** @return array<string, mixed>|null the template behind a theme code, with its artwork (also used by splash screens) */
    public function designFor(?string $themeId, int $companyId): ?array
    {
        if ($themeId === null) {
            return null;
        }
        $template = EventTemplate::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('code', $themeId)
            ->with(['frameStyle' => static fn ($q) => $q->withoutGlobalScopes()->where('is_active', 1), 'decorations' => static fn ($q) => $q->withoutGlobalScopes()->where('decorations.is_active', 1)])
            ->first(self::DESIGN_COLUMNS);
        if ($template === null) {
            return null;
        }

        return [
            ...$template->only(array_diff(self::DESIGN_COLUMNS, ['frame_style_id'])),
            'frame_url' => $template->frameStyle?->getAttribute('file_url'),
            'decorationItems' => $template->decorations->map(static fn (Decoration $d): array => $d->only(['id', 'name', 'type', 'file_url']))->values()->all(),
        ];
    }

    /** @return array<string, int> */
    private function guestStats(int $eventId): array
    {
        $c = $this->participantCounts([$eventId])[$eventId] ?? ['guests' => 0, 'joined' => 0, 'confirmed' => 0, 'pending' => 0, 'invited' => 0];

        return ['invited_guests' => $c['guests'], 'guests_joined' => $c['joined'], 'confirmed' => $c['confirmed'], 'pending' => $c['pending'], 'invitations_sent' => $c['invited']];
    }

    /**
     * Participant counts per event, one query for all.
     *
     * @param  list<int>  $eventIds
     * @return array<int, array{guests: int, joined: int, confirmed: int, pending: int, invited: int}>
     */
    private function participantCounts(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $invited = self::$invitationsSent !== null ? (self::$invitationsSent)($eventIds) : [];

        /** @var Collection<int, Participant> $rows */
        $rows = Participant::query()->whereIn('event_id', $eventIds)
            ->selectRaw("event_id, count(*) as guests, sum(client_id is not null) as joined, sum(response_type = 'yes') as confirmed, sum(response_type in ('none','maybe')) as pending")
            ->groupBy('event_id')->get();

        $counts = [];
        foreach ($eventIds as $id) {
            $row = $rows->firstWhere('event_id', $id);
            $counts[$id] = [
                'guests' => (int) $row?->getAttribute('guests'),
                'joined' => (int) $row?->getAttribute('joined'),
                'confirmed' => (int) $row?->getAttribute('confirmed'),
                'pending' => (int) $row?->getAttribute('pending'),
                'invited' => (int) ($invited[$id] ?? 0),
            ];
        }

        return $counts;
    }
}
