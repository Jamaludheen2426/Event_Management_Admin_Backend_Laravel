<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Phone;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;

/**
 * Who may do what with an event. One rule instead of v1's middleware that
 * swapped the signed-in client for the event's owner:
 *
 *  - the owner manages the event;
 *  - the organizer (organizer_client_id, set from the event's contact number)
 *    manages it as the owner would — while the owner's account is active —
 *    but may not delete it;
 *  - a participant (joined by QR, or whose number is on the guest list) may
 *    view it.
 *
 * Another client's event is "not found", never "forbidden".
 */
final class EventAccess
{
    /** An event the client may manage (owner or organizer). */
    public function manageable(Client $actor, int $eventId): Event
    {
        $event = Event::query()->with('client')->find($eventId);
        if ($event === null || ! $this->canManage($actor, $event)) {
            throw ApiException::notFound('Event not found.');
        }

        return $event;
    }

    /** An event the client owns (delete, and nothing an organizer may do). */
    public function owned(Client $actor, int $eventId): Event
    {
        $event = Event::query()->find($eventId);
        if ($event !== null && $event->client_id !== $actor->id && $event->organizer_client_id === $actor->id) {
            throw ApiException::forbidden('Only the client who owns this event can delete it.');
        }
        if ($event === null || $event->client_id !== $actor->id) {
            throw ApiException::notFound('Event not found.');
        }

        return $event;
    }

    /**
     * An event the client may see — manager or participant — and whether they
     * may change it.
     *
     * @return array{0: Event, 1: bool}
     */
    public function viewable(Client $actor, int $eventId, string $missing = 'That event is not on your account.'): array
    {
        $event = Event::query()->with(['client', 'settings', 'menuChoices'])->find($eventId)
            ?? throw ApiException::notFound('That event was not found.');
        if ($this->canManage($actor, $event)) {
            return [$event, true];
        }
        if (! $this->isParticipant($actor, $eventId)) {
            throw ApiException::notFound($missing);
        }

        return [$event, false];
    }

    public function canManage(Client $actor, Event $event): bool
    {
        if ($event->client_id === $actor->id) {
            return true;
        }

        return $event->organizer_client_id === $actor->id && $event->client->isActive();
    }

    public function isOrganizer(Client $actor, Event $event): bool
    {
        return $event->client_id !== $actor->id && $event->organizer_client_id === $actor->id;
    }

    /** A participant of the event: joined with this account, or this account's number is on the list. */
    public function isParticipant(Client $actor, int $eventId): bool
    {
        $numbers = array_values(array_filter(Phone::candidates($actor->mobile), static fn (string $d): bool => strlen($d) >= 7));

        return Participant::query()->where('event_id', $eventId)
            ->where(static function ($q) use ($actor, $numbers): void {
                $q->where('client_id', $actor->id);
                if ($numbers !== []) {
                    $q->orWhereIn('mobile', $numbers);
                }
            })->exists();
    }

    /**
     * The client whose plan governs this client's event work: their own, or —
     * for an organizer without a plan — the owner of the newest event they
     * organise (v1: the wizard showed the owner's catalogue).
     */
    public function planHolder(Client $actor): Client
    {
        if ($actor->plan_id !== null) {
            return $actor;
        }
        $event = Event::query()->where('organizer_client_id', $actor->id)->where('client_id', '!=', $actor->id)->latest('id')->with('client')->first();

        return $event !== null && $event->client->isActive() ? $event->client : $actor;
    }

    /**
     * Ids of every event this client may manage: their own and the ones they
     * organise (while the owner is active).
     *
     * @return list<int>
     */
    public function managedEventIds(Client $actor): array
    {
        return Event::query()->where(static fn ($q) => $q->where('client_id', $actor->id)
            ->orWhere(static fn ($o) => $o->where('organizer_client_id', $actor->id)->whereHas('client', static fn ($c) => $c->where('is_active', 1))))
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * Events this client organises for somebody else: event id => owner id.
     *
     * @return array<int, int>
     */
    public function organised(Client $actor): array
    {
        return Event::query()->where('organizer_client_id', $actor->id)->where('client_id', '!=', $actor->id)
            ->pluck('client_id', 'id')->map(static fn ($v): int => (int) $v)->all();
    }
}
