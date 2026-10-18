<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Models\ParticipantResponseLog;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Events\Support\MessageLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * RSVPs (v1 clientRsvp.service). There is no RSVP table: an RSVP is the answer
 * columns on a participant. "Delete RSVP" therefore CLEARS the answer — the
 * participant stays on the list and can answer again — and every change,
 * including a cleared answer, is written to participant_response_logs.
 */
final class Rsvps
{
    /** stored rsvp_status → the screen's four tabs */
    public const BUCKET = ['accepted' => 'accepted', 'declined' => 'declined', 'pending' => 'maybe', 'not_responded' => 'no_response', 'invited' => 'no_response'];

    private const LOGGED = ['response_type', 'party_size', 'dietary_preference', 'accommodation', 'notes'];

    public function __construct(
        private readonly EventAccess $access,
        private readonly MessageLog $messages,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  event_id, side, relationship, status, search, from, to, page, limit
     * @return array<string, mixed>
     */
    public function list(Client $actor, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 10)));
        $query = $this->filtered($actor, $filters);
        $total = (clone $query)->count();
        $rows = $query->with('event:id,name,start_date,start_time,venue_name,venue_address')
            ->orderByRaw('responded_at is null')->orderByDesc('responded_at')->orderByDesc('id')
            ->forPage($page, $limit)->get();

        return [
            'rsvps' => $rows->map(fn (Participant $p): array => $this->shape($p))->all(),
            'pagination' => ['page' => $page, 'limit' => $limit, 'totalItems' => $total, 'totalPages' => max(1, (int) ceil($total / $limit))],
            'stats' => $this->stats($actor, $filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float>
     */
    public function stats(Client $actor, array $filters): array
    {
        $rows = $this->filtered($actor, [...$filters, 'status' => 'all'])
            ->selectRaw('rsvp_status, count(*) as n, sum(party_size) as heads')->groupBy('rsvp_status')->get();
        $buckets = ['accepted' => 0, 'maybe' => 0, 'declined' => 0, 'no_response' => 0];
        $total = 0;
        $heads = 0;
        foreach ($rows as $row) {
            $n = (int) $row->getAttribute('n');
            $buckets[self::BUCKET[$row->rsvp_status] ?? 'no_response'] += $n;
            $total += $n;
            $heads += (int) $row->getAttribute('heads');
        }
        $pct = static fn (int $n): float => $total > 0 ? round($n / $total * 100, 1) : 0;

        return [
            'total_invitations' => $total, 'heads' => $heads,
            'accepted' => $buckets['accepted'], 'accepted_pct' => $pct($buckets['accepted']),
            'maybe' => $buckets['maybe'], 'maybe_pct' => $pct($buckets['maybe']),
            'declined' => $buckets['declined'], 'declined_pct' => $pct($buckets['declined']),
            'no_response' => $buckets['no_response'], 'no_response_pct' => $pct($buckets['no_response']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function export(Client $actor, array $filters): array
    {
        $rows = $this->filtered($actor, $filters)->with('event:id,name,start_date,start_time,venue_name,venue_address')
            ->orderByRaw('responded_at is null')->orderByDesc('responded_at')->limit(5000)->get();

        return ['rows' => $rows->map(fn (Participant $p): array => $this->shape($p))->all(), 'count' => $rows->count(), 'truncated' => $rows->count() >= 5000, 'stats' => $this->stats($actor, $filters)];
    }

    public function find(Client $actor, int $id): Participant
    {
        return Participant::query()->whereIn('event_id', $this->access->managedEventIds($actor))
            ->with('event:id,client_id,name,start_date,start_time,venue_name,venue_address')->find($id)
            ?? throw ApiException::notFound('RSVP not found.');
    }

    /** @return array<string, mixed> the RSVP detail screen */
    public function detail(Client $actor, Participant $p): array
    {
        $messages = $this->messages->forParticipants([$p->id]);
        $linked = $p->getAttribute('email') !== null
            ? Participant::query()->whereIn('event_id', $this->access->managedEventIds($actor))->where('email', $p->getAttribute('email'))->whereKeyNot($p->id)
                ->with('event:id,name,start_date,venue_name')->orderByDesc('id')->limit(25)->get()
            : collect();

        return [
            'rsvp' => $this->shape($p),
            'timeline' => $this->timeline($p, $messages),
            'messages' => array_map(static fn (array $m): array => array_intersect_key($m, array_flip(['id', 'channel', 'kind', 'status', 'sent_at', 'delivered_at', 'opened_at'])), $messages),
            'linked_events' => $linked->map(static fn (Participant $g): array => [
                'id' => $g->id,
                'rsvp_status' => $g->rsvp_status,
                'response_type' => $g->response_type,
                'bucket' => self::BUCKET[$g->rsvp_status] ?? 'no_response',
                'responded_at' => Dates::at($g->responded_at),
                'party_size' => $g->party_size ?: 1,
                'event' => $g->event->only(['id', 'name', 'start_date', 'venue_name']),
            ])->all(),
            'response_history' => $this->history([$p->id], 50),
            'unavailable' => ['custom_questions' => 'No custom questions are defined for this event, so answers cannot be labelled.'],
        ];
    }

    /**
     * The host edits the answer.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Client $actor, Participant $p, array $input): void
    {
        $snapshot = $this->snapshot($p);
        $values = [];
        if (array_key_exists('response_type', $input)) {
            $value = mb_strtolower(is_scalar($input['response_type']) ? (string) $input['response_type'] : 'none') ?: 'none';
            if (! in_array($value, ['none', 'yes', 'no', 'maybe'], true)) {
                throw ApiException::badRequest('Invalid response.');
            }
            $values['response_type'] = $value;
            $values['rsvp_status'] = ['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'pending', 'none' => 'invited'][$value];
            if ($value === 'none') {
                $values['responded_at'] = null;
            } elseif ($p->responded_at === null) {
                $values['responded_at'] = now();
            }
        }
        if (array_key_exists('party_size', $input)) {
            $size = filter_var($input['party_size'], FILTER_VALIDATE_INT);
            if ($size === false || $size < 1 || $size > 50) {
                throw ApiException::badRequest('Number of guests must be between 1 and 50.');
            }
            $values['party_size'] = $size;
        }
        foreach (['dietary_preference' => 100, 'special_requirements' => 500, 'notes' => 500] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $values[$field] = is_scalar($input[$field]) && (string) $input[$field] !== '' ? mb_substr((string) $input[$field], 0, $max) : null;
            }
        }
        if (array_key_exists('accommodation', $input)) {
            $value = mb_strtolower(is_scalar($input['accommodation']) ? (string) $input['accommodation'] : 'unknown') ?: 'unknown';
            $values['accommodation'] = in_array($value, ['unknown', 'required', 'not_required'], true) ? $value : throw ApiException::badRequest('Invalid accommodation value.');
        }
        if ($values === []) {
            throw ApiException::badRequest('Nothing to update.');
        }

        $before = $p->response_type;
        $p->fill($values)->save();
        $this->log($p, $snapshot, $values, 'client', $actor->id);

        $after = $p->response_type;
        if ($after !== $before && in_array($after, ['yes', 'no', 'maybe'], true)) {
            $word = ['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'maybe'][$after];
            ClientNotice::dispatch($p->event->client_id, 'rsvp_'.$word, 'RSVP updated', "{$p->name}'s response was changed to {$word}.", "/dashboard/rsvps/{$p->id}", $p->event_id, $p->id, ['response' => $after, 'source' => 'manual']);
        }
    }

    /** Clear the answer: the participant can respond again; the history keeps what they said. */
    public function reset(Client $actor, Participant $p, mixed $reason): void
    {
        $snapshot = $this->snapshot($p);
        $notes = is_scalar($reason) && (string) $reason !== '' ? mb_substr((string) $reason, 0, 500) : null;
        $p->fill(['response_type' => 'none', 'rsvp_status' => $p->getAttribute('invited_at') !== null ? 'invited' : 'not_responded', 'responded_at' => null, 'notes' => $notes])->save();
        $this->log($p, $snapshot, ['response_type' => 'none', 'notes' => $notes], 'client', $actor->id);
    }

    /**
     * Record an answer change (only when an answer field actually changed).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $values
     */
    public function log(Participant $p, array $before, array $values, string $source, ?int $changedBy): void
    {
        $changed = false;
        foreach (self::LOGGED as $field) {
            if (array_key_exists($field, $values) && $values[$field] !== $before[$field]) {
                $changed = true;
            }
        }
        if (! $changed) {
            return;
        }
        $pick = static fn (string $f) => array_key_exists($f, $values) ? $values[$f] : $before[$f];
        $first = ! ParticipantResponseLog::query()->where('participant_id', $p->id)->exists();

        ParticipantResponseLog::query()->create([
            'participant_id' => $p->id,
            'from_response_type' => $first ? null : $before['response_type'],
            'to_response_type' => $pick('response_type'),
            'party_size' => $pick('party_size') ?: 1,
            'dietary_preference' => $pick('dietary_preference'),
            'accommodation' => $pick('accommodation') ?: 'unknown',
            'notes' => $pick('notes'),
            'source' => $source,
            'changed_by_client_id' => $changedBy,
            'changed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> the answer as it stands, before a change */
    public function snapshot(Participant $p): array
    {
        return [
            'response_type' => $p->response_type,
            'party_size' => $p->party_size,
            'dietary_preference' => $p->getAttribute('dietary_preference'),
            'accommodation' => $p->getAttribute('accommodation'),
            'notes' => $p->getAttribute('notes'),
        ];
    }

    /**
     * @param  list<int>  $participantIds
     * @return list<array<string, mixed>>
     */
    public function history(array $participantIds, int $limit): array
    {
        return ParticipantResponseLog::query()->whereIn('participant_id', $participantIds)
            ->with(['participant' => static fn ($q) => $q->select(['id', 'event_id'])->with('event:id,name,start_date')])
            ->orderByDesc('changed_at')->orderByDesc('id')->limit($limit)->get()
            ->map(static fn (ParticipantResponseLog $h): array => [
                'id' => $h->id,
                'from_response_type' => $h->from_response_type,
                'to_response_type' => $h->to_response_type,
                'party_size' => $h->party_size,
                'dietary_preference' => $h->dietary_preference,
                'accommodation' => $h->accommodation,
                'notes' => $h->notes,
                'source' => $h->source,
                'changed_at' => Dates::at($h->changed_at),
                'is_first' => $h->from_response_type === null,
                'event' => $h->participant->event->only(['id', 'name', 'start_date']),
            ])->all();
    }

    /** @return array<string, mixed> the v1 RSVP row */
    public function shape(Participant $p): array
    {
        $a = $p->attributesToArray();

        return [
            'id' => $p->id,
            'guest' => [
                'id' => $p->id, 'name' => $p->name, 'first_name' => $a['first_name'] ?? null, 'last_name' => $a['last_name'] ?? null,
                'title' => $a['title'] ?? null, 'email' => $a['email'] ?? null, 'dial_code' => $a['dial_code'] ?? null, 'mobile' => $p->mobile,
                'whatsapp' => $a['whatsapp'] ?? null, 'company' => $a['company'] ?? null, 'table_number' => $a['table_number'] ?? null,
                'city' => $a['city'] ?? null, 'state' => $a['state'] ?? null, 'country' => $a['country'] ?? null, 'photo' => $p->photo,
                'relationship' => $a['relationship'] ?? null,
            ],
            'phone_book_guest_id' => $p->guest_id,
            'accommodation' => $a['accommodation'] ?? 'unknown',
            'added_by_client_id' => $a['added_by_client_id'] ?? null,
            'event' => $p->relationLoaded('event') ? $p->event->only(['id', 'name', 'start_date', 'start_time', 'venue_name', 'venue_address']) : null,
            'rsvp_status' => $p->rsvp_status,
            'response_type' => $p->response_type,
            'bucket' => self::BUCKET[$p->rsvp_status] ?? 'no_response',
            'party_size' => $p->party_size ?: 1,
            'rsvp_side' => $a['rsvp_side'] ?? null,
            'dietary_preference' => $a['dietary_preference'] ?? null,
            'special_requirements' => $a['special_requirements'] ?? null,
            'plus_one' => (bool) ($a['plus_one'] ?? false),
            'plus_one_count' => (int) ($a['plus_one_count'] ?? 0),
            'notes' => $a['notes'] ?? null,
            'custom_answers' => $a['custom_answers'] ?? null,
            'invite_source' => $p->invite_source,
            'invited_at' => $a['invited_at'] ?? null,
            'responded_at' => $a['responded_at'] ?? null,
            'created_at' => $a['created_at'] ?? null,
            'updated_at' => $a['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Participant>
     */
    private function filtered(Client $actor, array $filters): Builder
    {
        $query = Participant::query()->whereIn('participants.event_id', $this->access->managedEventIds($actor));
        $event = $filters['event_id'] ?? null;
        if (is_numeric($event)) {
            $query->where('event_id', (int) $event);
        }
        if (in_array($filters['side'] ?? null, ['groom', 'bride'], true)) {
            $query->where('rsvp_side', $filters['side']);
        }
        $relationship = $filters['relationship'] ?? null;
        if (is_string($relationship) && $relationship !== '' && $relationship !== 'all') {
            $query->where('relationship', trim($relationship));
        }
        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'all') {
            $stored = array_keys(array_filter(self::BUCKET, static fn (string $b): bool => $b === $status));
            $query->whereIn('rsvp_status', $stored !== [] ? $stored : [$status]);
        }
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        if ($search !== '') {
            $query->where(static fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%"));
        }
        foreach (['from' => ['>=', 'startOfDay'], 'to' => ['<=', 'endOfDay']] as $key => [$op, $edge]) {
            $value = $filters[$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $query->where('responded_at', $op, Carbon::parse($value)->{$edge}());
            }
        }

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function timeline(Participant $p, array $messages): array
    {
        $entries = [['key' => 'created', 'label' => 'Invitation created', 'detail' => "{$p->name} was added to the guest list.", 'at' => $p->getAttribute('created_at')]];
        if ($p->getAttribute('invited_at') !== null) {
            $entries[] = ['key' => 'invited', 'label' => 'Invitation sent', 'detail' => "Sent via {$p->invite_source}.", 'at' => $p->getAttribute('invited_at')];
        }
        foreach ($messages as $m) {
            $entries[] = [
                'key' => 'msg-'.$m['id'],
                'label' => ($m['kind'] ?? null) === 'reminder' ? 'Reminder sent' : 'Message sent',
                'detail' => (($m['channel'] ?? null) === 'email' ? 'Email' : 'WhatsApp').' — '.($m['status'] ?? '').'.',
                'at' => isset($m['sent_at']) ? Carbon::parse($m['sent_at']) : (isset($m['created_at']) ? Carbon::parse($m['created_at']) : null),
                'channel' => $m['channel'] ?? null,
            ];
        }
        if ($p->responded_at !== null) {
            $verb = ['yes' => 'accepted', 'no' => 'declined', 'maybe' => 'replied "maybe" to'][$p->response_type] ?? 'responded to';
            $entries[] = ['key' => 'responded', 'label' => 'RSVP received', 'detail' => "{$p->name} {$verb} the invitation.", 'at' => $p->responded_at];
        }
        if ($p->event->start_date !== null) {
            $entries[] = ['key' => 'event', 'label' => 'Event date', 'detail' => 'The event is scheduled to take place.',
                'at' => Carbon::parse($p->event->start_date->format('Y-m-d').' '.($p->event->start_time ?? '00:00:00')), 'upcoming' => true];
        }
        $entries = array_values(array_filter($entries, static fn (array $e): bool => $e['at'] !== null));
        usort($entries, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_map(static fn (array $e): array => [...$e, 'at' => Dates::at($e['at'])], $entries);
    }
}
