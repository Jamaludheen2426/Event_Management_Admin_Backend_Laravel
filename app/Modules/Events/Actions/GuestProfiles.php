<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\GuestNote;
use App\Modules\Events\Models\GuestReminder;
use App\Modules\Events\Models\GuestTag;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\MessageLog;
use Illuminate\Support\Carbon;

/**
 * A phone-book guest as a PERSON across the host's events (v1
 * clientGuestProfile.service): the events they joined (participants linked by
 * guest_id), messages, answer history, and what the host wrote about them —
 * notes, tags, reminders. Host notes are never the guest's own RSVP note.
 */
final class GuestProfiles
{
    private const NOTE_CATEGORIES = ['general', 'personal', 'dietary', 'communication', 'reminder', 'logistics'];

    private const TINTS = ['violet', 'emerald', 'blue', 'amber', 'pink', 'cyan'];

    public function __construct(
        private readonly GuestBook $book,
        private readonly Rsvps $rsvps,
        private readonly MessageLog $messages,
    ) {}

    /** @return array<string, mixed> */
    public function show(Client $host, int $guestId): array
    {
        $guest = $this->book->find($host, $guestId);
        $siblings = Participant::query()->where('guest_id', $guest->id)->whereHas('event', static fn ($q) => $q->where('client_id', $host->id))
            ->with('event:id,name,start_date,start_time,venue_name')->orderByDesc('id')->limit(50)->get();
        $ids = $siblings->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $messages = $this->messages->forParticipants($ids);
        $notes = GuestNote::query()->where('guest_id', $guest->id)->orderByDesc('is_pinned')->orderByDesc('created_at')->limit(100)->get();
        $tags = GuestTag::query()->where('guest_id', $guest->id)->orderBy('created_at')->limit(50)->get()->unique('label');
        $reminders = GuestReminder::query()->where('guest_id', $guest->id)->orderBy('due_at')->limit(50)->get();
        $latest = $siblings->first();
        $g = $guest->attributesToArray();
        $lastContact = collect($messages)->map(static fn (array $m) => $m['sent_at'] ?? $m['created_at'] ?? null)->filter()->sortDesc()->first();

        return [
            'guest' => [
                'id' => $guest->id, 'name' => $guest->name, 'first_name' => $g['first_name'] ?? null, 'last_name' => $g['last_name'] ?? null,
                'title' => $g['title'] ?? null, 'email' => $g['email'] ?? null, 'dial_code' => $g['dial_code'] ?? null, 'mobile' => $guest->mobile,
                'whatsapp' => $g['whatsapp'] ?? null, 'company' => $g['company'] ?? null,
                'table_number' => $latest?->getAttribute('table_number'),
                'photo' => $g['photo'] ?? null, 'relationship' => $g['relationship'] ?? null,
                'accommodation' => $latest?->getAttribute('accommodation') ?? 'unknown',
                'location' => implode(', ', array_filter([$g['city'] ?? null, $g['state'] ?? null, $g['country'] ?? null])) ?: null,
                'city' => $g['city'] ?? null, 'state' => $g['state'] ?? null, 'country' => $g['country'] ?? null,
                'group' => $guest->group?->only(['id', 'name', 'color']),
                'rsvp_status' => $latest?->rsvp_status, 'response_type' => $latest?->response_type,
                'responded_at' => Dates::at($latest?->responded_at), 'invited_at' => Dates::at($latest?->getAttribute('invited_at')),
                'party_size' => $latest?->party_size,
                'dietary_preference' => $g['dietary_preference'] ?? null, 'special_requirements' => $g['special_requirements'] ?? null,
                'response_note' => $g['notes'] ?? null,
                'custom_answers' => $latest?->getAttribute('custom_answers'),
                'created_at' => $g['created_at'] ?? null,
            ],
            'event' => $latest?->event?->only(['id', 'name', 'start_date', 'start_time', 'venue_name']),
            'identity' => [
                'linked_by' => 'guest',
                'email' => $g['email'] ?? null,
                'guest_row_ids' => $ids,
                'events_invited' => $siblings->count(),
                'note' => $siblings->isNotEmpty()
                    ? 'Events this guest joined by scanning the invitation QR with their mobile number.'
                    : 'This guest has not joined any event yet. Share an invitation with them — when they scan its QR with this mobile number, the event appears here.',
            ],
            'summary' => [
                'events_invited' => $siblings->count(),
                'last_contact' => $lastContact,
                'total_messages' => count($messages),
                'opened' => count(array_filter($messages, static fn (array $m): bool => ! empty($m['opened_at']))),
                'delivered' => count(array_filter($messages, static fn (array $m): bool => ($m['status'] ?? null) === 'delivered')),
                'pending' => count(array_filter($messages, static fn (array $m): bool => ($m['status'] ?? null) === 'queued')),
                'notes' => $notes->count(),
                'reminders_open' => $reminders->where('status', 'pending')->count(),
            ],
            'linked_events' => $siblings->map(static fn (Participant $p): array => [
                'id' => $p->id, 'is_current' => false, 'rsvp_status' => $p->rsvp_status, 'response_type' => $p->response_type,
                'responded_at' => Dates::at($p->responded_at), 'invited_at' => Dates::at($p->getAttribute('invited_at')),
                'invitation_sent' => $p->getAttribute('invited_at') !== null, 'party_size' => $p->party_size,
                'accommodation' => $p->getAttribute('accommodation'), 'dietary_preference' => $p->getAttribute('dietary_preference'),
                'event' => $p->event->only(['id', 'name', 'start_date', 'start_time', 'venue_name']),
            ])->all(),
            'messages' => array_map(static fn (array $m): array => [
                'id' => $m['id'] ?? null, 'guest_id' => $m['participant_id'] ?? null, 'channel' => $m['channel'] ?? null, 'kind' => $m['kind'] ?? null,
                'status' => $m['status'] ?? null, 'sent_at' => $m['sent_at'] ?? null, 'delivered_at' => $m['delivered_at'] ?? null,
                'opened_at' => $m['opened_at'] ?? null, 'sender' => $m['sender'] ?? null,
                'sender_name' => ($m['sender'] ?? null) === 'client' ? $host->name : 'System', 'event' => $m['event'] ?? null,
            ], $messages),
            'response_history' => $ids !== [] ? $this->rsvps->history($ids, 100) : [],
            'notes' => $notes->map(fn (GuestNote $n): array => $this->note($n, $host))->all(),
            'tags' => $tags->map(fn (GuestTag $t): array => ['id' => $t->id, 'label' => $t->label, 'color' => $t->color, 'tint' => $t->color === null ? $this->tint($t->label) : null])->values()->all(),
            'reminders' => $reminders->map(static fn (GuestReminder $r): array => [
                'id' => $r->id, 'note_id' => $r->note_id, 'title' => $r->title, 'due_at' => Dates::at($r->due_at), 'status' => $r->status,
                'completed_at' => Dates::at($r->completed_at), 'state' => $r->state(),
            ])->all(),
            'unavailable' => ['custom_questions' => 'No custom questions are defined for this event, so stored answers cannot be labelled.'],
        ];
    }

    /**
     * Photo and relationship only.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Client $host, int $guestId, array $input): void
    {
        $guest = $this->book->find($host, $guestId);
        $values = [];
        foreach (['photo' => [500, 'Photo must be a URL string.'], 'relationship' => [60, 'Relationship must be text.']] as $field => [$max, $message]) {
            if (array_key_exists($field, $input)) {
                if ($input[$field] !== null && ! is_string($input[$field])) {
                    throw ApiException::badRequest($message);
                }
                $values[$field] = $input[$field] !== null && trim($input[$field]) !== '' ? mb_substr(trim($input[$field]), 0, $max) : null;
            }
        }
        if ($values === []) {
            throw ApiException::badRequest('Nothing to update.');
        }
        $guest->fill($values)->save();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int}
     */
    public function addNote(Client $host, int $guestId, array $input): array
    {
        $guest = $this->book->find($host, $guestId);
        $title = $this->title($input['title'] ?? null, 'A note needs a title.');
        $this->assertBody($input);

        $note = GuestNote::query()->create([
            'guest_id' => $guest->id,
            'title' => $title,
            'body' => ($input['body'] ?? null) ?: null,
            'category' => in_array($input['category'] ?? null, self::NOTE_CATEGORIES, true) ? $input['category'] : 'general',
            'visibility' => in_array($input['visibility'] ?? null, ['internal', 'shared'], true) ? $input['visibility'] : 'internal',
            'is_pinned' => (bool) ($input['is_pinned'] ?? false),
            'created_by_client_id' => $host->id,
        ]);

        return ['id' => $note->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int}
     */
    public function updateNote(Client $host, int $guestId, int $noteId, array $input): array
    {
        $note = $this->ownNote($host, $guestId, $noteId);
        $values = [];
        if (array_key_exists('title', $input)) {
            $values['title'] = $this->title($input['title'], 'A note needs a title.');
        }
        if (array_key_exists('body', $input)) {
            $this->assertBody($input);
            $values['body'] = $input['body'] ?: null;
        }
        if (array_key_exists('category', $input)) {
            $values['category'] = in_array($input['category'], self::NOTE_CATEGORIES, true) ? $input['category'] : throw ApiException::badRequest('Invalid category.');
        }
        if (array_key_exists('visibility', $input)) {
            $values['visibility'] = in_array($input['visibility'], ['internal', 'shared'], true) ? $input['visibility'] : throw ApiException::badRequest('Invalid visibility.');
        }
        if (array_key_exists('is_pinned', $input)) {
            $values['is_pinned'] = (bool) $input['is_pinned'];
        }
        if ($values === []) {
            throw ApiException::badRequest('Nothing to update.');
        }
        $note->fill($values)->save();

        return ['id' => $note->id];
    }

    /** @return array{id: int} */
    public function deleteNote(Client $host, int $guestId, int $noteId): array
    {
        $note = $this->ownNote($host, $guestId, $noteId);
        $note->delete();

        return ['id' => $note->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int}
     */
    public function addTag(Client $host, int $guestId, array $input): array
    {
        $guest = $this->book->find($host, $guestId);
        if (! is_string($input['label'] ?? null) || trim($input['label']) === '') {
            throw ApiException::badRequest('A tag needs a label.');
        }
        if (isset($input['color']) && ! is_string($input['color'])) {
            throw ApiException::badRequest('Tag colour must be text.');
        }
        $label = mb_substr(trim($input['label']), 0, 50);
        $existing = GuestTag::query()->withTrashed()->where('guest_id', $guest->id)->where('label', $label)->first();
        if ($existing !== null) {
            if (! $existing->trashed()) {
                throw ApiException::badRequest('That tag is already on this guest.');
            }
            $existing->restore();

            return ['id' => $existing->id];
        }

        return ['id' => GuestTag::query()->create(['guest_id' => $guest->id, 'label' => $label, 'color' => isset($input['color']) ? mb_substr($input['color'], 0, 9) : null])->id];
    }

    /** @return array{id: int} */
    public function removeTag(Client $host, int $guestId, int $tagId): array
    {
        $guest = $this->book->find($host, $guestId);
        $tag = GuestTag::query()->where('guest_id', $guest->id)->find($tagId) ?? throw ApiException::notFound('Tag not found.');
        $tag->delete();

        return ['id' => $tag->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int}
     */
    public function addReminder(Client $host, int $guestId, array $input): array
    {
        $guest = $this->book->find($host, $guestId);
        $title = $this->title($input['title'] ?? null, 'A reminder needs a title.');
        $due = $this->date($input['due_at'] ?? null) ?? throw ApiException::badRequest('A reminder needs a valid date.');
        if ($due->isPast()) {
            throw ApiException::badRequest('That reminder date has already passed.');
        }
        $noteId = null;
        if (! empty($input['note_id'])) {
            $noteId = GuestNote::query()->where('guest_id', $guest->id)->whereKey((int) $input['note_id'])->value('id')
                ?? throw ApiException::badRequest('That note is not on this guest.');
        }

        return ['id' => GuestReminder::query()->create(['guest_id' => $guest->id, 'note_id' => $noteId, 'title' => $title, 'due_at' => $due, 'created_by_client_id' => $host->id])->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{id: int}
     */
    public function updateReminder(Client $host, int $guestId, int $reminderId, array $input): array
    {
        $guest = $this->book->find($host, $guestId);
        $reminder = GuestReminder::query()->where('guest_id', $guest->id)->find($reminderId) ?? throw ApiException::notFound('Reminder not found.');
        $values = [];
        if (array_key_exists('title', $input)) {
            $values['title'] = $this->title($input['title'], 'A reminder needs a title.');
        }
        if (array_key_exists('due_at', $input)) {
            $values['due_at'] = $this->date($input['due_at']) ?? throw ApiException::badRequest('Invalid reminder date.');
        }
        if (array_key_exists('status', $input)) {
            $values['status'] = in_array($input['status'], ['pending', 'done', 'dismissed'], true) ? $input['status'] : throw ApiException::badRequest('Invalid reminder status.');
            $values['completed_at'] = $input['status'] === 'done' ? now() : null;
        }
        if ($values === []) {
            throw ApiException::badRequest('Nothing to update.');
        }
        $reminder->fill($values)->save();

        return ['id' => $reminder->id];
    }

    /** @return array{id: int} */
    public function deleteReminder(Client $host, int $guestId, int $reminderId): array
    {
        $guest = $this->book->find($host, $guestId);
        $reminder = GuestReminder::query()->where('guest_id', $guest->id)->find($reminderId) ?? throw ApiException::notFound('Reminder not found.');
        $reminder->delete();

        return ['id' => $reminder->id];
    }

    private function ownNote(Client $host, int $guestId, int $noteId): GuestNote
    {
        $guest = $this->book->find($host, $guestId);

        return GuestNote::query()->where('guest_id', $guest->id)->find($noteId) ?? throw ApiException::notFound('Note not found.');
    }

    /** @return array<string, mixed> */
    private function note(GuestNote $n, Client $host): array
    {
        return [
            'id' => $n->id, 'title' => $n->title, 'body' => $n->body ?? '', 'category' => $n->category, 'visibility' => $n->visibility,
            'is_pinned' => $n->is_pinned, 'created_at' => Dates::at($n->created_at), 'updated_at' => Dates::at($n->updated_at),
            'edited' => $n->created_at !== null && $n->updated_at !== null && $n->updated_at->diffInSeconds($n->created_at, true) > 1,
            'author' => $n->created_by_client_id !== null ? $host->name : null,
        ];
    }

    private function title(mixed $value, string $message): string
    {
        // A title must already BE text: "[object Object]" is not a title.
        if (! is_string($value) || trim($value) === '') {
            throw ApiException::badRequest($message);
        }

        return mb_substr(trim($value), 0, 150);
    }

    /** @param array<string, mixed> $input */
    private function assertBody(array $input): void
    {
        if (isset($input['body']) && ! is_string($input['body'])) {
            throw ApiException::badRequest('Note content must be text.');
        }
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function tint(string $label): string
    {
        $hash = 0;
        foreach (mb_str_split($label) as $char) {
            $hash = ($hash * 31 + mb_ord($char)) & 0xFFFFFFFF;
        }

        return self::TINTS[$hash % count(self::TINTS)];
    }
}
