<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientNotificationPref;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Messaging\Models\ClientNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The client's notification feed (v1 clientNotification.service): writes a
 * row for every ClientNotice, lists with category / unread / event / search
 * filters, and keeps the stats the tabs and the badge show.
 */
final class ClientNotifications
{
    /** v1 TYPE_CATEGORY: which tab a type lands in; unknown types are "system". */
    public const TYPE_CATEGORY = [
        'campaign_sent' => 'system', 'campaign_scheduled' => 'system', 'guest_message' => 'message',
        'event_notification' => 'message', 'wish_flagged' => 'system', 'rsvp_accepted' => 'rsvp',
        'rsvp_declined' => 'rsvp', 'rsvp_maybe' => 'rsvp', 'guest_added' => 'guest', 'guest_imported' => 'guest',
        'welcome_invitation' => 'guest', 'event_reminder' => 'reminder', 'draft_reminder' => 'reminder',
        'payment_received' => 'system', 'event_published' => 'system',
    ];

    public const CATEGORIES = ['rsvp', 'reminder', 'message', 'system', 'guest'];

    /**
     * Records one notice, unless the client switched that type off. A failure
     * never breaks the action that caused it (v1).
     */
    public function record(ClientNotice $notice): ?ClientNotification
    {
        try {
            $off = ClientNotificationPref::query()->where('client_id', $notice->clientId)
                ->where('channel', 'in_app')->where('type', $notice->type)->where('enabled', false)->exists();
            if ($off) {
                return null;
            }

            return ClientNotification::query()->create($this->row($notice));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Several notices in one insert (v1 notifyMany): a broadcast to a whole
     * guest list is one query, not one per guest.
     *
     * @param  list<ClientNotice>  $notices
     */
    public function recordMany(array $notices): int
    {
        if ($notices === []) {
            return 0;
        }
        $now = now();
        $rows = array_map(fn (ClientNotice $n): array => [
            ...$this->row($n),
            'meta' => $n->meta === null ? null : json_encode($n->meta),
            'is_read' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $notices);
        ClientNotification::query()->insert($rows);

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $filters  category, unread, search, event_id, page, limit
     * @return array<string, mixed>
     */
    public function list(Client $client, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 10) ?: 10));
        $eventId = $this->eventId($filters['event_id'] ?? null);
        $category = $filters['category'] ?? null;

        $query = $this->feed($client->id)
            ->when($eventId !== null, static fn (Builder $q) => $q->where('event_id', $eventId))
            ->when(in_array($category, self::CATEGORIES, true), static fn (Builder $q) => $q->where('category', $category))
            ->when(in_array($filters['unread'] ?? null, [true, 'true'], true), static fn (Builder $q) => $q->where('is_read', false));
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(static fn (Builder $q) => $q->where('title', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%"));
        }

        $total = (clone $query)->count();
        $rows = $query->with(['event:id,name', 'participant:id,name,email,mobile'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $limit)->get();

        return [
            'notifications' => $rows->map(fn (ClientNotification $n): array => $this->shape($n))->all(),
            'pagination' => ['page' => $page, 'limit' => $limit, 'totalItems' => $total, 'totalPages' => max(1, (int) ceil($total / $limit))],
            'stats' => $this->stats($client->id, $eventId),
            'events' => $this->events($client->id),
        ];
    }

    /** @return array<string, mixed> */
    public function stats(int $clientId, ?int $eventId = null): array
    {
        $rows = $this->feed($clientId)
            ->when($eventId !== null, static fn (Builder $q) => $q->where('event_id', $eventId))
            ->groupBy('category')
            ->get(['category', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread')]);

        $counts = array_fill_keys(self::CATEGORIES, ['total' => 0, 'unread' => 0]);
        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('category')] = ['total' => (int) $row->getAttribute('total'), 'unread' => (int) $row->getAttribute('unread')];
        }

        return [
            'total' => array_sum(array_column($counts, 'total')),
            'unread' => array_sum(array_column($counts, 'unread')),
            'reminders' => $counts['reminder']['total'],
            'guest_activity' => $counts['guest']['total'] + $counts['rsvp']['total'],
            'by_category' => $counts,
        ];
    }

    public function unreadCount(Client $client): int
    {
        return $this->feed($client->id)->where('is_read', false)->count();
    }

    /** @return array<string, mixed> */
    public function markRead(Client $client, int $id, bool $read): array
    {
        $row = $this->own($client, $id);
        $row->forceFill(['is_read' => $read, 'read_at' => $read ? now() : null])->save();

        return $this->shape($row);
    }

    /**
     * Scoped to the tab the client is looking at: "mark all as read" on the
     * RSVP tab leaves the System tab alone (v1).
     *
     * @return array{marked: int, stats: array<string, mixed>}
     */
    public function markAllRead(Client $client, mixed $category, mixed $eventId): array
    {
        $event = $this->eventId($eventId);
        $marked = $this->feed($client->id)->where('is_read', false)
            ->when(in_array($category, self::CATEGORIES, true), static fn (Builder $q) => $q->where('category', $category))
            ->when($event !== null, static fn (Builder $q) => $q->where('event_id', $event))
            ->update(['is_read' => true, 'read_at' => now()]);

        return ['marked' => $marked, 'stats' => $this->stats($client->id, $event)];
    }

    /** @return array{id: int, stats: array<string, mixed>} */
    public function archive(Client $client, int $id): array
    {
        $row = $this->own($client, $id);
        $row->forceFill(['archived_at' => now(), ...($row->is_read ? [] : ['is_read' => true, 'read_at' => now()])])->save();

        return ['id' => $row->id, 'stats' => $this->stats($client->id)];
    }

    /** @return array<string, mixed> */
    private function row(ClientNotice $notice): array
    {
        return [
            'client_id' => $notice->clientId,
            'category' => self::TYPE_CATEGORY[$notice->type] ?? 'system',
            'type' => $notice->type,
            'title' => mb_substr($notice->title, 0, 200),
            'body' => $notice->body === '' ? null : mb_substr($notice->body, 0, 500),
            'event_id' => $notice->eventId,
            'participant_id' => $notice->participantId,
            'link' => $notice->link,
            'meta' => $notice->meta,
        ];
    }

    /** @return Builder<ClientNotification> */
    private function feed(int $clientId): Builder
    {
        return ClientNotification::query()->where('client_id', $clientId)->whereNull('archived_at');
    }

    private function own(Client $client, int $id): ClientNotification
    {
        return ClientNotification::query()->where('client_id', $client->id)->find($id)
            ?? throw ApiException::notFound('Notification not found.');
    }

    private function eventId(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * The event filter: events the client owns or joined, plus any event the
     * feed mentions, with how many notifications each has.
     *
     * @return list<array{id: int, name: string, total: int}>
     */
    private function events(int $clientId): array
    {
        $counted = $this->feed($clientId)->whereNotNull('event_id')->groupBy('event_id')
            ->selectRaw('event_id, COUNT(*) as total')->pluck('total', 'event_id');
        $owned = Event::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('client_id', $clientId)->pluck('name', 'id');
        $joined = Event::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('id', Participant::query()->where('client_id', $clientId)->select('event_id'))->pluck('name', 'id');
        $mentioned = Event::query()->withoutGlobalScopes()->whereNull('deleted_at')->whereKey($counted->keys())->pluck('name', 'id');

        $events = [];
        foreach ([$owned, $joined, $mentioned] as $list) {
            foreach ($list as $id => $name) {
                $events[(int) $id] ??= ['id' => (int) $id, 'name' => (string) $name, 'total' => (int) ($counted[$id] ?? 0)];
            }
        }
        usort($events, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $events;
    }

    /** @return array<string, mixed> */
    private function shape(ClientNotification $n): array
    {
        $event = $n->relationLoaded('event') ? $n->event : null;
        $guest = $n->relationLoaded('participant') ? $n->participant : null;

        return [
            'id' => $n->id,
            'category' => $n->category,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'event_id' => $n->event_id,
            'guest_id' => $n->participant_id,
            'link' => $n->link,
            'meta' => $n->meta,
            'is_read' => $n->is_read,
            'read_at' => Dates::at($n->read_at),
            'created_at' => Dates::at($n->created_at),
            'event' => $event === null ? null : ['id' => $event->id, 'name' => $event->name],
            'guest' => $guest === null ? null : ['id' => $guest->id, 'name' => $guest->name, 'email' => $guest->getAttribute('email'), 'mobile' => $guest->mobile],
        ];
    }
}
