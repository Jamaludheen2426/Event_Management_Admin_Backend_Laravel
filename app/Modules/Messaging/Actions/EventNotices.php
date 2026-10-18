<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Messaging\Models\Broadcast;
use App\Modules\Messaging\Models\BroadcastRecipient;
use App\Modules\Messaging\Support\AppPush;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The app's Home → Send Notification (v1 clientEventNotification.service): an
 * organizer writes to everyone, chosen participants, or a side / relation,
 * as a draft, now, or at a time in a chosen time zone. Every participant with
 * an account gets it in the bell; push when Firebase is set up.
 */
final class EventNotices
{
    private const TITLE_MAX = 100;

    private const MESSAGE_MAX = 500;

    private const SIDES = ['bride', 'groom'];

    private const CHANNELS = ['in_app', 'push'];

    private const EDITABLE = ['draft', 'scheduled'];

    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly EventAccess $access,
        private readonly AppPush $push,
        private readonly ClientNotifications $feed,
        private readonly ObjectStorage $storage,
    ) {}

    /** @return array{notifications: list<array<string, mixed>>} */
    public function list(Client $client, int $eventId): array
    {
        $event = $this->event($client, $eventId);

        return ['notifications' => $this->notices()->where('event_id', $event->id)->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (Broadcast $b): array => $this->shape($b))->all()];
    }

    /** @return array<string, mixed> */
    public function find(Client $client, int $id): array
    {
        return $this->shape($this->own($client, $id));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{notification: array<string, mixed>, outcome: array<string, mixed>|null}
     */
    public function create(Client $client, int $eventId, array $input): array
    {
        return $this->commit($client, $this->event($client, $eventId), null, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{notification: array<string, mixed>, outcome: array<string, mixed>|null}
     */
    public function update(Client $client, int $id, array $input): array
    {
        $row = $this->own($client, $id);
        if (! in_array($row->status, self::EDITABLE, true)) {
            throw ApiException::badRequest('A notification that has been sent cannot be edited.');
        }

        return $this->commit($client, $this->event($client, $row->event_id), $row, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function reschedule(Client $client, int $id, array $input): array
    {
        $row = $this->own($client, $id);
        if ($row->status !== 'scheduled') {
            throw ApiException::badRequest('Only a scheduled notification can be rescheduled.');
        }
        $moved = Broadcast::query()->whereKey($row->id)->where('status', 'scheduled')->update($this->schedule($input));
        if ($moved === 0) {
            throw ApiException::badRequest('That notification is already being sent.');
        }

        return $this->shape($row->refresh());
    }

    /** @return array{id: int} */
    public function delete(Client $client, int $id): array
    {
        $row = $this->own($client, $id);
        if ($row->status === 'sending') {
            throw ApiException::badRequest('That notification is being sent. Try again in a moment.');
        }
        $row->delete();

        return ['id' => $row->id];
    }

    /** @return array<string, mixed> */
    public function audienceOptions(Client $client, int $eventId): array
    {
        $participants = $this->participants($this->event($client, $eventId), ['audience' => 'all']);
        $relations = $participants->map(static fn (Participant $p): string => trim((string) $p->getAttribute('relationship')))
            ->filter()->countBy()->sortKeys();

        return [
            'total' => $participants->count(),
            'sides' => array_map(static fn (string $side): array => ['key' => $side, 'count' => $participants->where('rsvp_side', $side)->count()], self::SIDES),
            'relationships' => $relations->map(static fn (int $count, string $name): array => ['name' => $name, 'count' => $count])->values()->all(),
            'participants' => $participants->map(static fn (Participant $p): array => [
                'id' => $p->id, 'name' => $p->name, 'photo' => $p->photo ?: null, 'side' => $p->getAttribute('rsvp_side') ?: null,
                'relationship' => $p->getAttribute('relationship') ?: null, 'has_app' => $p->client_id !== null,
            ])->all(),
        ];
    }

    /** @return array{url: string} */
    public function uploadImage(Client $client, int $eventId, ?UploadedFile $file): array
    {
        if ($file === null) {
            throw ApiException::badRequest('Please choose an image to upload.');
        }
        $event = $this->event($client, $eventId);
        if (! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ApiException::badRequest('Please choose a JPG, PNG or WEBP image.');
        }
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw ApiException::badRequest('That image is larger than 5MB.');
        }

        return ['url' => $this->storage->upload($file, 'event-notifications/'.$event->id)['url']];
    }

    /** Scheduled notifications that are due (every minute). */
    public function sendDue(): int
    {
        $fired = 0;
        $due = $this->notices()->where('status', 'scheduled')->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')->limit(25)->get();
        foreach ($due as $row) {
            if (Broadcast::query()->whereKey($row->id)->where('status', 'scheduled')->update(['status' => 'sending']) === 0) {
                continue;
            }
            $fired++;
            try {
                $event = Event::query()->withoutGlobalScopes()->find($row->event_id)
                    ?? throw ApiException::notFound('The event of this notification no longer exists.');
                $this->deliver($row->refresh(), $event);
            } catch (Throwable $e) {
                report($e);
                Broadcast::query()->whereKey($row->id)->update(['status' => 'failed', 'failed_reason' => mb_substr($e->getMessage(), 0, 255)]);
            }
        }

        return $fired;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{notification: array<string, mixed>, outcome: array<string, mixed>|null}
     */
    private function commit(Client $client, Event $event, ?Broadcast $existing, array $input): array
    {
        $action = in_array($input['action'] ?? null, ['draft', 'send', 'schedule'], true) ? (string) $input['action'] : 'draft';
        $fields = $this->fields($input, $action);
        $save = function (array $values) use ($client, $event, $existing): Broadcast {
            if ($existing !== null) {
                $existing->fill($values)->save();

                return $existing;
            }

            return Broadcast::query()->create([...$values, 'event_id' => $event->id, 'sent_by_client_id' => $client->id, 'source' => Broadcast::NOTICE]);
        };

        if ($action === 'draft') {
            return ['notification' => $this->shape($save([...$fields, 'status' => 'draft', 'scheduled_at' => null, 'timezone' => null, 'recipients_count' => 0])), 'outcome' => null];
        }

        $audience = $this->participants($event, $fields);
        if ($audience->isEmpty()) {
            throw ApiException::badRequest('Nobody matches the chosen audience.');
        }
        if ($action === 'schedule') {
            $row = $save([...$fields, ...$this->schedule($input), 'status' => 'scheduled', 'recipients_count' => $audience->count()]);

            return ['notification' => $this->shape($row), 'outcome' => null];
        }

        $row = $save([...$fields, 'status' => 'sending', 'scheduled_at' => null, 'timezone' => null, 'recipients_count' => $audience->count()]);
        try {
            $outcome = $this->deliver($row, $event);
        } catch (Throwable $e) {
            $row->forceFill(['status' => 'failed', 'failed_reason' => mb_substr($e->getMessage(), 0, 255)])->save();
            throw $e;
        }

        return ['notification' => $this->shape($row), 'outcome' => $outcome];
    }

    /** @return array{recipients: int, delivered: int, failed: int, push?: bool} */
    private function deliver(Broadcast $row, Event $event): array
    {
        $participants = $this->participants($event, $row->only(['audience', 'participant_ids', 'sides', 'relationships']));
        if ($participants->isEmpty()) {
            $row->forceFill(['status' => 'failed', 'failed_reason' => 'Nobody matches the chosen audience.', 'recipients_count' => 0])->save();

            return ['recipients' => 0, 'delivered' => 0, 'failed' => 0];
        }

        $channels = $row->channels ?: ['push'];
        $push = in_array('push', $channels, true);
        $accounts = $participants->pluck('client_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $outcomes = $push ? $this->push->send($event, $accounts, [
            'title' => $row->title,
            'body' => (string) $row->body,
            'image_url' => preg_match('#^https?://#i', (string) $row->image_url) === 1 ? $row->image_url : null,
            'deep_link' => "eventinvit://event/{$event->id}",
            'data' => ['type' => 'event_notification', 'event_notification_id' => $row->id, 'event_id' => $event->id],
        ]) : [];

        $now = now();
        $delivered = 0;
        $rows = $participants->map(function (Participant $p) use ($row, $event, $push, $outcomes, $now, &$delivered): array {
            $outcome = $p->client_id === null ? null : ($outcomes[$p->client_id] ?? null);
            $ok = $push ? ($outcome['ok'] ?? false) : $p->client_id !== null;
            $delivered += (int) $ok;

            return [
                'broadcast_id' => $row->id, 'event_id' => $event->id, 'participant_id' => $p->id, 'client_id' => $p->client_id,
                'channel' => $push ? 'push' : 'in_app', 'status' => $ok ? 'sent' : 'failed', 'sender' => 'client',
                'sender_client_id' => $row->sent_by_client_id, 'sent_at' => $ok ? $now : null, 'is_read' => false,
                'failed_reason' => $ok ? null : mb_substr($p->client_id === null ? 'Has not joined the app yet' : ($outcome['reason'] ?? 'No active device'), 0, 255),
                'created_at' => $now, 'updated_at' => $now,
            ];
        });
        foreach ($rows->chunk(500) as $chunk) {
            BroadcastRecipient::query()->insertOrIgnore($chunk->values()->all());
        }

        // The bell: one row per account, not per participant row.
        $this->feed->recordMany(array_map(static fn (int $clientId): ClientNotice => new ClientNotice(
            $clientId, 'event_notification', $row->title, (string) $row->body, "eventinvit://event/{$event->id}", $event->id, null,
            ['event_notification_id' => $row->id, 'image_url' => $row->image_url, 'channels' => $channels],
        ), $accounts));

        $reasons = collect($outcomes)->where('ok', false)->pluck('reason')->unique();
        $failed = $rows->count() - $delivered;
        $row->forceFill([
            'status' => 'sent', 'sent_at' => $now, 'recipients_count' => $rows->count(), 'delivered_count' => $delivered,
            'failed_count' => $failed, 'failed_reason' => $delivered === 0 && $reasons->count() === 1 ? mb_substr((string) $reasons->first(), 0, 255) : null,
        ])->save();

        return ['recipients' => $rows->count(), 'delivered' => $delivered, 'failed' => $failed, 'push' => $push];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return Collection<int, Participant>
     */
    private function participants(Event $event, array $fields): Collection
    {
        $query = Participant::query()->where('event_id', $event->id);
        if (($fields['audience'] ?? 'all') === 'specific') {
            $ids = $this->listOf($fields['participant_ids'] ?? null, 'int');
            if ($ids === []) {
                return collect();
            }
            $query->whereKey($ids);
        } elseif (($fields['audience'] ?? 'all') === 'groups') {
            $sides = array_values(array_intersect($this->listOf($fields['sides'] ?? null), self::SIDES));
            $relations = $this->listOf($fields['relationships'] ?? null);
            // Both sides = every side.
            if (count($sides) === 1) {
                $query->where('rsvp_side', $sides[0]);
            }
            if ($relations !== []) {
                $query->whereIn('relationship', $relations);
            }
        }

        return $query->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function fields(array $input, string $action): array
    {
        $draft = $action === 'draft';
        $title = trim($this->text($input['title'] ?? null));
        if ($title === '') {
            throw ApiException::badRequest('Please enter a notification title.');
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw ApiException::badRequest('The title can be at most '.self::TITLE_MAX.' characters.');
        }
        $message = trim($this->text($input['message'] ?? null));
        if ($message === '' && ! $draft) {
            throw ApiException::badRequest('Please write a message.');
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw ApiException::badRequest('The message can be at most '.self::MESSAGE_MAX.' characters.');
        }
        $image = trim($this->text($input['image_url'] ?? null));
        if ($image !== '' && preg_match('#^(https?://|/uploads/)#i', $image) !== 1) {
            throw ApiException::badRequest('That image is not valid.');
        }

        $audience = in_array($input['audience'] ?? null, ['all', 'specific', 'groups'], true) ? (string) $input['audience'] : 'all';
        $participantIds = $this->listOf($input['participant_ids'] ?? null, 'int');
        $sides = array_values(array_intersect($this->listOf($input['sides'] ?? null), self::SIDES));
        $relations = array_values(array_unique(array_filter(array_map(static fn (string $r): string => mb_substr(trim($r), 0, 60), $this->listOf($input['relationships'] ?? null)))));
        $asked = array_key_exists('channels', $input) ? $this->listOf($input['channels']) : ['push'];
        $channels = array_values(array_intersect(self::CHANNELS, $asked));

        if (! $draft) {
            if ($channels === []) {
                throw ApiException::badRequest('Please choose In-App, Push Notification or both.');
            }
            if ($audience === 'specific' && $participantIds === []) {
                throw ApiException::badRequest('Please choose at least one participant.');
            }
            if ($audience === 'groups' && $sides === []) {
                throw ApiException::badRequest('Please choose the bride side, the groom side or both.');
            }
        }

        return [
            'title' => $title, 'body' => $message === '' ? null : $message, 'image_url' => $image === '' ? null : $image,
            'audience' => $audience,
            'participant_ids' => $audience === 'specific' ? $participantIds : null,
            'sides' => $audience === 'groups' ? $sides : null,
            'relationships' => $audience === 'groups' ? $relations : null,
            'channels' => $channels,
        ];
    }

    /**
     * Date and time as the organizer's wall clock in the zone they picked
     * ("(GMT+05:30) Chennai"), stored as the instant.
     *
     * @param  array<string, mixed>  $input
     * @return array{scheduled_at: Carbon, timezone: string}
     */
    private function schedule(array $input): array
    {
        $timezone = mb_substr(trim($this->text($input['timezone'] ?? null)), 0, 80);
        $date = $this->text($input['scheduled_date'] ?? null);
        $time = $this->text($input['scheduled_time'] ?? null);
        if ($date === '' || $time === '' || $timezone === '') {
            throw ApiException::badRequest('Please choose the date, the time and the time zone.');
        }
        $offset = self::offset($timezone);
        if ($offset === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time) !== 1) {
            throw ApiException::badRequest('That date, time or time zone is not valid.');
        }
        $at = Carbon::parse($date.' '.substr($time, 0, 5), 'UTC')->subMinutes($offset);
        if ($at->lte(now())) {
            throw ApiException::badRequest('That time has already passed. Choose a later time.');
        }

        return ['scheduled_at' => $at, 'timezone' => $timezone];
    }

    /** Minutes east of UTC from a "GMT+05:30" label. */
    private static function offset(string $label): ?int
    {
        if (preg_match('/GMT\s*([+-])\s*(\d{1,2}):(\d{2})/i', $label, $m) !== 1) {
            return null;
        }
        $minutes = (int) $m[2] * 60 + (int) $m[3];

        return $m[1] === '-' ? -$minutes : $minutes;
    }

    /** @return array<string, mixed> */
    private function shape(Broadcast $b): array
    {
        $offset = self::offset((string) $b->timezone);
        $wall = $b->scheduled_at !== null && $offset !== null ? $b->scheduled_at->copy()->utc()->addMinutes($offset) : null;

        return [
            'id' => $b->id, 'event_id' => $b->event_id, 'title' => $b->title, 'message' => (string) $b->body,
            'image_url' => $b->image_url, 'audience' => $b->audience, 'participant_ids' => $b->participant_ids ?? [],
            'sides' => $b->sides ?? [], 'relationships' => $b->relationships ?? [], 'channels' => $b->channels ?? [],
            'status' => $b->status, 'scheduled_at' => Dates::at($b->scheduled_at),
            'scheduled_date' => $wall?->format('Y-m-d'), 'scheduled_time' => $wall?->format('H:i'),
            'timezone' => $b->timezone, 'sent_at' => Dates::at($b->sent_at), 'recipients_count' => $b->recipients_count,
            'delivered_count' => $b->delivered_count, 'failed_count' => $b->failed_count, 'failed_reason' => $b->failed_reason,
            'created_at' => Dates::at($b->created_at),
        ];
    }

    private function event(Client $client, int $eventId): Event
    {
        if (! in_array($eventId, $this->access->managedEventIds($client), true)) {
            throw ApiException::notFound('That event is not on your account.');
        }

        return Event::query()->findOrFail($eventId);
    }

    private function own(Client $client, int $id): Broadcast
    {
        return $this->notices()->whereIn('event_id', $this->access->managedEventIds($client))->find($id)
            ?? throw ApiException::notFound('That notification was not found.');
    }

    /** @return Builder<Broadcast> */
    private function notices(): Builder
    {
        return Broadcast::query()->where('source', Broadcast::NOTICE);
    }

    /**
     * A list from JSON or an array (v1 listOf).
     *
     * @return ($type is 'int' ? list<int> : list<string>)
     */
    private function listOf(mixed $raw, string $type = 'string'): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }
        if ($type === 'int') {
            return array_values(array_unique(array_filter(array_map('intval', array_filter($raw, 'is_numeric')))));
        }

        return array_values(array_unique(array_map('strval', array_filter($raw, 'is_scalar'))));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
