<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Phone;
use App\Core\Tenancy\VendorScope;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Events\ParticipantAdded;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\GuestFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The people attending a host's events (v1 clientParticipant.service): added
 * by hand here, or by scanning the QR (GuestRegistration). One mobile number
 * per event; the plan's max_rsvp_per_event caps how many may join.
 */
final class Participants
{
    public function __construct(
        private readonly EventAccess $access,
        private readonly GuestBook $book,
    ) {}

    /** @return Builder<Participant> participants of the events this client manages */
    public function query(Client $actor): Builder
    {
        return Participant::query()->whereIn('participants.event_id', $this->access->managedEventIds($actor))
            ->with(['event:id,client_id,name,start_date,start_time,theme_id,venue_name,venue_address', 'guest:id,name']);
    }

    /** @param Builder<Participant> $query */
    public function filter(Builder $query, Request $request): void
    {
        $tab = mb_strtolower((string) $request->query('status', 'all'));
        $search = trim((string) $request->query('search', ''));
        if (is_numeric($request->query('event_id'))) {
            $query->where('event_id', (int) $request->query('event_id'));
        }
        match (true) {
            $tab === 'joined' => $query->whereNotNull('client_id'),
            $tab === 'not_responded' => $query->whereIn('rsvp_status', ['not_responded', 'invited']),
            in_array($tab, GuestFields::RSVP_STATUSES, true) => $query->where('rsvp_status', $tab),
            default => null,
        };
        $side = $request->query('side');
        if (in_array($side, ['groom', 'bride'], true)) {
            $query->where('rsvp_side', $side);
        }
        $relationship = $request->query('relationship');
        if (is_string($relationship) && $relationship !== '' && $relationship !== 'all') {
            $query->where('relationship', trim($relationship));
        }
        if ($search !== '') {
            $query->where(static fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%"));
        }
    }

    public function find(Client $actor, int $id): Participant
    {
        return $this->query($actor)->find($id) ?? throw ApiException::notFound('Participant not found.');
    }

    /** @return array<string, int|float> */
    public function stats(Client $actor, mixed $eventId): array
    {
        $rows = Participant::query()->whereIn('event_id', $this->access->managedEventIds($actor))
            ->when(is_numeric($eventId), static fn ($q) => $q->where('event_id', (int) $eventId))
            ->get(['rsvp_status', 'party_size', 'invite_source']);
        $total = $rows->count();
        $count = static fn (string ...$s): int => $rows->whereIn('rsvp_status', $s)->count();
        $pct = static fn (int $n): float => $total > 0 ? round($n / $total * 100, 1) : 0;
        $awaiting = $count('not_responded', 'invited');

        return [
            'total_guests' => (int) $rows->sum(static fn (Participant $p): int => $p->party_size ?: 1),
            'total_rows' => $total,
            'accepted' => $count('accepted'), 'accepted_pct' => $pct($count('accepted')),
            'pending' => $count('pending'), 'pending_pct' => $pct($count('pending')),
            'declined' => $count('declined'), 'declined_pct' => $pct($count('declined')),
            'not_responded' => $awaiting, 'not_responded_pct' => $pct($awaiting),
            'imported' => $rows->where('invite_source', 'import')->count(),
        ];
    }

    /** @return array{limit: int|null, used: int, full: bool} */
    public function capacity(Client $actor, mixed $eventId): array
    {
        return $this->status($this->managedEvent($actor, $eventId));
    }

    /** @return array{limit: int|null, used: int, full: bool} */
    public function status(Event $event): array
    {
        $limit = $this->limit($event);
        $used = Participant::query()->where('event_id', $event->id)->count();

        return ['limit' => $limit, 'used' => $used, 'full' => $limit !== null && $used >= $limit];
    }

    /** @param array<string, mixed> $input */
    public function create(Client $actor, array $input): Participant
    {
        $person = GuestFields::person($actor->id, $input, partial: false, withGroup: false);
        $attendance = GuestFields::attendance($input);

        $participant = DB::transaction(function () use ($actor, $input, $person, $attendance): Participant {
            $event = $this->managedEvent($actor, $input['event_id'] ?? null);
            $this->assertCapacity($event, 1);
            $this->assertNumberFree($event->id, $person['mobile'] ?? null);
            $guest = $this->book->byNumber($event->client_id, $person['mobile'] ?? null);
            if (($person['title'] ?? null) === null && $guest?->getAttribute('title') !== null) {
                $person['title'] = $guest->getAttribute('title');
            }
            $account = $this->accountFor($event, $person['mobile'] ?? null, $person['photo'] ?? null);

            return Participant::query()->create([
                'invite_source' => 'manual',
                ...$person,
                ...$attendance,
                'event_id' => $event->id,
                'guest_id' => $guest?->id,
                'client_id' => $account?->id,
                'added_by_client_id' => $actor->id,
            ]);
        });

        ClientNotice::dispatch($participant->event->client_id, 'guest_added', 'New participant added', "{$participant->name} was added to your event.", "/dashboard/rsvps/{$participant->id}", $participant->event_id, $participant->id);
        ParticipantAdded::dispatch($participant);

        return $this->find($actor, $participant->id);
    }

    /** @param array<string, mixed> $input */
    public function update(Client $actor, Participant $participant, array $input): Participant
    {
        $existing = $participant->attributesToArray();
        $values = [
            ...GuestFields::person($actor->id, $input, partial: true, existing: $existing, withGroup: false),
            ...GuestFields::attendance($input, $existing),
        ];
        $before = $participant->response_type;
        $moving = array_key_exists('event_id', $input) && (int) $input['event_id'] !== $participant->event_id;

        DB::transaction(function () use ($actor, $participant, $input, $values, $moving): void {
            $event = $participant->event;
            if ($moving) {
                $event = $this->managedEvent($actor, $input['event_id']);
                $this->assertCapacity($event, 1);
                $values['event_id'] = $event->id;
            }
            if (! empty($values['mobile']) || $moving) {
                $this->assertNumberFree($event->id, $values['mobile'] ?? $participant->mobile, $participant->id);
            }
            if (! empty($values['mobile'])) {
                $guest = $this->book->byNumber($event->client_id, $values['mobile']);
                $values['guest_id'] = $guest?->id;
                $account = $this->accountFor($event, $values['mobile'], $values['photo'] ?? $guest?->getAttribute('photo'));
                if ($account !== null) {
                    $values['client_id'] = $account->id;
                }
            }
            $participant->fill($values)->save();
        });

        $after = $participant->response_type;
        if ($after !== $before && in_array($after, ['yes', 'no', 'maybe'], true)) {
            $verb = ['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'tentatively replied to'][$after];
            ClientNotice::dispatch($participant->event->client_id, 'rsvp_'.['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'maybe'][$after], 'New RSVP received', "{$participant->name} {$verb} your invitation.", "/dashboard/rsvps/{$participant->id}", $participant->event_id, $participant->id, ['response' => $after, 'mobile' => $participant->mobile]);
        }

        return $this->find($actor, $participant->id);
    }

    /** @return array<string, mixed> v1 present(): the row plus full_name, is_imported, has_joined, side */
    public function present(Participant $p): array
    {
        $data = $p->toArray();
        unset($data['client_id']);

        return [
            ...$data,
            'website_client_id' => $p->event->client_id,
            'full_name' => $p->name,
            'is_imported' => $p->invite_source === 'import',
            'has_joined' => $p->client_id !== null,
            'side' => $p->getAttribute('rsvp_side'),
            'rsvp_side' => $p->getAttribute('rsvp_side'),
        ];
    }

    public function assertCapacity(Event $event, int $adding, ?string $message = null): void
    {
        Event::query()->whereKey($event->id)->lockForUpdate()->value('id');
        $max = $this->limit($event);
        if ($max === null) {
            return;
        }
        $used = Participant::query()->where('event_id', $event->id)->count();
        if ($used + $adding > $max) {
            $left = max(0, $max - $used);
            throw ApiException::badRequest($message ?? ($left === 0
                ? "This event has reached its limit of {$max} participant".($max === 1 ? '' : 's').'. Please upgrade your plan to allow more.'
                : "This event can take only {$left} more participant".($left === 1 ? '' : 's')." (limit {$max}). Please add fewer or upgrade your plan."));
        }
    }

    /** The participant limit of the event's owner's plan (null = unlimited). */
    public function limit(Event $event): ?int
    {
        $planId = $event->client->plan_id ?? $event->plan_id;
        $value = $planId !== null ? Plan::query()->withoutGlobalScopes()->withTrashed()->whereKey($planId)->value('max_rsvp_per_event') : null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function managedEvent(Client $actor, mixed $eventId): Event
    {
        if (! is_numeric($eventId) || (int) $eventId <= 0) {
            throw ApiException::badRequest('Please select an event.');
        }
        $event = Event::query()->with('client')->find((int) $eventId);
        if ($event === null || ! $this->access->canManage($actor, $event)) {
            throw ApiException::badRequest('That event is not on your account.');
        }

        return $event;
    }

    private function assertNumberFree(int $eventId, ?string $mobile, ?int $except = null): void
    {
        $key = GuestFields::mobileKey($mobile);
        if ($key === '') {
            return;
        }
        $clash = Participant::query()->where('event_id', $eventId)->when($except !== null, static fn ($q) => $q->whereKeyNot($except))
            ->whereRaw("right(regexp_replace(coalesce(mobile, ''), '[^0-9]', ''), 10) = ?", [$key])->first(['id', 'name']);
        if ($clash !== null) {
            throw ApiException::conflict("{$clash->name} is already a participant of this event.");
        }
    }

    /** The client account (same vendor) with this number; gives it the photo if it has none. */
    private function accountFor(Event $event, ?string $mobile, ?string $photo): ?Client
    {
        $numbers = array_values(array_filter(Phone::candidates($mobile), static fn (string $d): bool => strlen($d) >= 7));
        if ($numbers === []) {
            return null;
        }
        $account = Client::query()->withoutGlobalScope(VendorScope::class)->where('vendor_id', $event->vendor_id)->whereIn('mobile', $numbers)->first();
        if ($account !== null && $account->avatar_url === null && $photo !== null) {
            $account->forceFill(['avatar_url' => $photo])->save();
        }

        return $account;
    }
}
