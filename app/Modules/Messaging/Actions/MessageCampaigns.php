<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Clients\Events\ClientNotice;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Guest;
use App\Modules\Events\Models\GuestGroup;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\EventAccess;
use App\Modules\Messaging\Jobs\DeliverEmailBroadcast;
use App\Modules\Messaging\Models\Broadcast;
use App\Modules\Messaging\Models\BroadcastRecipient;
use App\Modules\Messaging\Support\AppPush;
use App\Modules\Messaging\Support\MailTransports;
use App\Modules\Messaging\Support\MergeFields;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The Messages screen (v1 clientMessage.service): compose for an event's
 * guests by group or by hand, preview with merge fields filled in, send now
 * or at a time, and the history with delivery counts.
 *
 * Push goes out through Firebase and e-mail through Settings → Email when
 * they are set up. WhatsApp has no provider yet: the message is recorded
 * and says so (v1).
 */
final class MessageCampaigns
{
    public const CHANNELS = ['whatsapp', 'email', 'push'];

    private const KINDS = ['invite', 'reminder', 'update', 'thank_you', 'custom'];

    private const LABELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email', 'push' => 'Push Notification'];

    private const MAX_TTL = 2_419_200;

    public function __construct(
        private readonly EventAccess $access,
        private readonly AppPush $push,
        private readonly MailTransports $mail,
        private readonly ClientNotifications $feed,
    ) {}

    /**
     * Whether each channel can deliver right now, and why not.
     *
     * @return list<array{channel: string, label: string, enabled: bool, reason: string|null}>
     */
    public function channels(?Event $event): array
    {
        $notConnected = static fn (string $label): string => "{$label} is not connected yet. Your message is saved and recorded, but it will not be delivered until a provider is configured.";
        $email = $event !== null && $this->mail->configuredFor($this->push->companyOf($event));
        $push = $event === null ? ['enabled' => false, 'reason' => 'Choose an event.'] : $this->push->availability($event);

        return [
            ['channel' => 'whatsapp', 'label' => 'WhatsApp', 'enabled' => false, 'reason' => $notConnected('WhatsApp')],
            ['channel' => 'email', 'label' => 'Email', 'enabled' => $email, 'reason' => $email ? null : $notConnected('Email')],
            ['channel' => 'push', 'label' => 'Push Notification', 'enabled' => $push['enabled'], 'reason' => $push['reason']],
        ];
    }

    /** @return array<string, mixed> */
    public function composer(Client $client, mixed $eventId): array
    {
        $events = Event::query()->whereIn('id', $this->access->managedEventIds($client))->where('status', '!=', 'cancelled')
            ->withCount('participants as guest_count')
            ->orderByRaw('start_date IS NULL')->orderBy('start_date')->orderBy('id')->get();

        $today = now()->toDateString();
        $chosen = is_numeric($eventId)
            ? $events->firstWhere('id', (int) $eventId)
            : ($events->first(static fn (Event $e): bool => $e->start_date !== null && $e->start_date->toDateString() >= $today)
                ?? $events->filter(static fn (Event $e): bool => $e->start_date !== null)->last()
                ?? $events->first());

        $groups = [];
        $guestCount = 0;
        if ($chosen instanceof Event) {
            $counts = Participant::query()->where('event_id', $chosen->id)
                ->join('guests', 'guests.id', '=', 'participants.guest_id')->whereNull('guests.deleted_at')
                ->groupBy('guests.group_id')->selectRaw('guests.group_id, COUNT(*) as total')->pluck('total', 'group_id');
            $groups = GuestGroup::query()->where('client_id', $chosen->client_id)->orderBy('name')->get(['id', 'name', 'color'])
                ->map(static fn (GuestGroup $g): array => ['id' => $g->id, 'name' => $g->name, 'color' => $g->getAttribute('color'), 'guest_count' => (int) ($counts[$g->id] ?? 0)])->all();
            $guestCount = (int) $chosen->getAttribute('guest_count');
        }

        return [
            'events' => $events->map(static fn (Event $e): array => [
                'id' => $e->id, 'name' => $e->name, 'start_date' => Dates::day($e->start_date), 'start_time' => $e->start_time,
                'venue_name' => $e->getAttribute('venue_name'), 'status' => $e->status, 'guest_count' => (int) $e->getAttribute('guest_count'),
            ])->all(),
            'selected_event' => $chosen instanceof Event ? [
                'id' => $chosen->id, 'name' => $chosen->name, 'start_date' => Dates::day($chosen->start_date), 'start_time' => $chosen->start_time,
                'venue_name' => $chosen->getAttribute('venue_name'), 'venue_address' => $chosen->getAttribute('venue_address'),
            ] : null,
            'groups' => $groups,
            'guest_count' => $guestCount,
            'merge_fields' => MergeFields::FIELDS,
            'channels' => $this->channels($chosen instanceof Event ? $chosen : null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(Client $client, array $input): array
    {
        $event = $this->event($client, $input['event_id'] ?? null);
        $channel = in_array($input['channel'] ?? null, self::CHANNELS, true) ? (string) $input['channel'] : 'email';
        $a = $this->audience($event, $input, $channel);

        $bySide = [];
        foreach ($a['eligible'] as $g) {
            $side = match ($g->getAttribute('rsvp_side')) {
                'bride' => "Bride's side", 'groom' => "Groom's side", default => 'No side',
            };
            $bySide[$side] = ($bySide[$side] ?? 0) + 1;
        }
        $total = $a['eligible']->count();
        $sample = $a['eligible']->first();
        $unreachable = $a['unreachable']->count();

        return [
            'channel' => $channel,
            'total_recipients' => $total,
            'counts' => [
                'selected_guests' => $a['guests']->count(), 'heads' => $a['heads'], 'excluded_declined' => $a['declined'],
                'unreachable' => $unreachable, 'recipients' => $total,
            ],
            'breakdown' => array_map(static fn (string $name, int $count): array => [
                'name' => $name, 'count' => $count, 'percent' => $total > 0 ? round($count / $total * 100, 1) : 0,
            ], array_keys($bySide), array_values($bySide)),
            'unreachable' => [
                'count' => $unreachable,
                'reason' => $unreachable === 0 ? null : $this->unreachableReason($unreachable, $channel),
                'guests' => $a['unreachable']->take(25)->map(static fn (Participant $g): array => ['id' => $g->id, 'name' => $g->name])->values()->all(),
            ],
            'preview' => [
                'subject' => MergeFields::render($this->text($input['subject'] ?? null), $sample, $event, $client->name),
                'body' => MergeFields::render($this->text($input['body'] ?? null), $sample, $event, $client->name),
                'rendered_for' => $sample === null ? null : ['id' => $sample->id, 'name' => $sample->name],
            ],
            'channels' => $this->channels($event),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function send(Client $client, array $input): array
    {
        $event = $this->event($client, $input['event_id'] ?? null);
        $channel = strtolower($this->text($input['channel'] ?? null));
        if (! in_array($channel, self::CHANNELS, true)) {
            throw ApiException::badRequest('Choose WhatsApp or Email.');
        }
        $kind = in_array($input['kind'] ?? null, self::KINDS, true) ? (string) $input['kind'] : 'invite';
        $audience = in_array($input['audience'] ?? null, ['all', 'groups', 'guests'], true) ? (string) $input['audience'] : 'all';
        $body = trim($this->text($input['body'] ?? null));
        $subject = trim($this->text($input['subject'] ?? null));
        if ($body === '' || ($channel === 'email' && $subject === '')) {
            throw ApiException::badRequest('Please fill all mandatory fields.');
        }
        // The list shows a subject for every channel; WhatsApp and push have none of their own.
        if ($subject === '') {
            $subject = self::LABELS[$channel].' — '.$event->name;
        }

        $a = $this->audience($event, [...$input, 'audience' => $audience], $channel);
        $eligible = $a['eligible'];
        if ($eligible->isEmpty()) {
            $count = $a['unreachable']->count();
            throw ApiException::badRequest(match (true) {
                $count === 0 => 'No guests match that selection.',
                $channel === 'push' => "None of the {$count} selected guests has the app installed. A guest becomes reachable by push once they join the event through the mobile app and allow notifications.",
                default => "None of the {$count} selected guests has a ".($channel === 'email' ? 'email address' : 'phone number').' on file.',
            });
        }

        $scheduledAt = $this->scheduledAt($input['scheduled_at'] ?? null);
        $state = collect($this->channels($event))->firstWhere('channel', $channel);
        $enabled = (bool) ($state['enabled'] ?? false);
        $push = $channel === 'push' ? $this->pushOptions($input) : [];
        $now = now();

        $campaign = DB::transaction(function () use ($client, $event, $input, $channel, $kind, $audience, $subject, $body, $eligible, $scheduledAt, $enabled, $state, $push, $now): Broadcast {
            $campaign = Broadcast::query()->create([
                'event_id' => $event->id, 'sent_by_client_id' => $client->id, 'source' => Broadcast::MESSAGE,
                'title' => mb_substr($subject, 0, 255), 'body' => $body, 'channel' => $channel, 'kind' => $kind, 'audience' => $audience,
                'group_ids' => $audience === 'groups' ? $this->ids($input['group_ids'] ?? []) : null,
                'participant_ids' => $audience === 'guests' ? $this->ids($input['guest_ids'] ?? []) : null,
                ...$push,
                'recipients_count' => $eligible->count(),
                'status' => $scheduledAt !== null ? 'scheduled' : ($enabled ? 'sent' : 'sending'),
                'scheduled_at' => $scheduledAt,
                'timezone' => $this->text($input['timezone'] ?? null) ?: null,
                'sent_at' => $scheduledAt === null ? $now : null,
                // Stored on the row so an old campaign still explains itself later.
                'failed_reason' => $scheduledAt !== null || $enabled ? null : mb_substr((string) ($state['reason'] ?? ''), 0, 255),
            ]);

            // One insert for every recipient (production is ~374 ms a query).
            // queued, never sent: nothing has left the platform yet.
            BroadcastRecipient::query()->insert($eligible->map(static fn (Participant $g): array => [
                'broadcast_id' => $campaign->id, 'event_id' => $event->id, 'participant_id' => $g->id, 'client_id' => $g->client_id,
                'channel' => $channel, 'kind' => $kind === 'custom' ? 'update' : $kind, 'status' => 'queued',
                'sender' => 'client', 'sender_client_id' => $client->id, 'is_read' => false,
                'sent_at' => $scheduledAt === null ? $now : null, 'created_at' => $now, 'updated_at' => $now,
            ])->all());

            if ($kind === 'invite' && $scheduledAt === null) {
                Participant::query()->whereKey($eligible->pluck('id')->all())->where('rsvp_status', 'not_responded')->update([
                    'rsvp_status' => 'invited', 'invited_at' => $now,
                    ...($channel === 'push' ? [] : ['invite_source' => $channel]),
                ]);
            }

            return $campaign;
        });

        $outcome = null;
        if ($scheduledAt === null && $enabled) {
            $outcome = $this->deliver($campaign, $event);
        }

        $count = $eligible->count();
        $plural = $count === 1 ? '' : 's';
        ClientNotice::dispatch(
            $client->id,
            $scheduledAt !== null ? 'campaign_scheduled' : 'campaign_sent',
            $scheduledAt !== null ? 'Message scheduled' : self::LABELS[$channel].' message sent',
            $scheduledAt !== null
                ? "\"{$subject}\" is scheduled for {$count} guest{$plural} on {$event->name}."
                : "\"{$subject}\" was recorded for {$count} guest{$plural} on {$event->name}.",
            "/dashboard/messages/{$campaign->id}",
            $event->id,
            null,
            ['channel' => $channel, 'recipients' => $count, 'delivered' => $enabled],
        );

        return [
            'campaign' => $this->shape($campaign->setRelation('event', $event)),
            'recipients' => $count,
            'skipped' => $a['unreachable']->count(),
            'delivery' => [
                'attempted' => $enabled,
                'reason' => $enabled ? null : ($state['reason'] ?? null),
                ...($outcome !== null && $channel === 'push' ? ['delivered' => $outcome['delivered'], 'failed' => $outcome['failed']] : []),
            ],
        ];
    }

    /**
     * A test to the sender's own address. Only e-mail can actually go out.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function test(Client $client, array $input): array
    {
        $event = $this->event($client, $input['event_id'] ?? null);
        $channel = in_array($input['channel'] ?? null, self::CHANNELS, true) ? (string) $input['channel'] : 'email';
        $state = collect($this->channels($event))->firstWhere('channel', $channel);
        $destination = $channel === 'email' ? $client->email : $client->mobile;
        if ($destination === null || $destination === '') {
            throw ApiException::badRequest($channel === 'email'
                ? 'Your account has no email address to send a test to.'
                : 'Your account has no phone number to send a test to.');
        }
        $subject = MergeFields::render($this->text($input['subject'] ?? null), null, $event, $client->name);
        $body = MergeFields::render($this->text($input['body'] ?? null), null, $event, $client->name);

        $sent = false;
        $reason = null;
        if ($channel === 'email' && ($state['enabled'] ?? false)) {
            try {
                Mail::html(nl2br(e($body)), static fn ($m) => $m->to($destination)->subject('[TEST] '.($subject ?: $event->name)));
                $sent = true;
            } catch (Throwable $e) {
                report($e);
                $reason = "The test could not be delivered to {$destination}: {$e->getMessage()}";
            }
        } else {
            $label = $state['label'] ?? $channel;
            $reason = "{$label} is not connected yet, so no test could be delivered to {$destination}. The preview above is exactly what a guest would receive.";
        }

        return ['sent' => $sent, 'destination' => $destination, 'preview' => ['subject' => $subject, 'body' => $body], 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(Client $client, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 10) ?: 10));
        $eventIds = $this->access->managedEventIds($client);

        $query = Broadcast::query()->where('source', Broadcast::MESSAGE)->whereIn('event_id', $eventIds)
            ->when(in_array($filters['channel'] ?? null, self::CHANNELS, true), static fn (Builder $q) => $q->where('channel', $filters['channel']))
            ->when(($filters['status'] ?? '') !== '', static fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(is_numeric($filters['event_id'] ?? null), static fn (Builder $q) => $q->where('event_id', (int) $filters['event_id']))
            ->when(($filters['from'] ?? '') !== '', static fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay()))
            ->when(($filters['to'] ?? '') !== '', static fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay()));
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(static fn (Builder $q) => $q->where('title', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%"));
        }

        $total = (clone $query)->count();
        $rows = $query->with('event:id,name,start_date')->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $limit)->get();
        $delivery = BroadcastRecipient::query()->whereIn('broadcast_id', $rows->modelKeys())->groupBy('broadcast_id')
            ->selectRaw("broadcast_id, COUNT(*) as total, SUM(status = 'delivered') as delivered, SUM(status = 'failed') as failed, SUM(opened_at IS NOT NULL) as opened")
            ->get()->keyBy('broadcast_id');

        return [
            'campaigns' => $rows->map(function (Broadcast $b) use ($delivery): array {
                $d = $delivery->get($b->id);
                $sent = (int) $d?->getAttribute('total');
                $delivered = (int) $d?->getAttribute('delivered');

                return [...$this->shape($b), 'delivery' => $d === null
                    ? ['total' => $b->recipients_count, 'delivered' => 0, 'failed' => 0, 'opened' => 0, 'delivered_rate' => null]
                    : ['total' => $sent, 'delivered' => $delivered, 'failed' => (int) $d->getAttribute('failed'), 'opened' => (int) $d->getAttribute('opened'),
                        'delivered_rate' => $delivered > 0 ? round($delivered / $sent * 100, 1) : null]];
            })->all(),
            'pagination' => ['page' => $page, 'limit' => $limit, 'totalItems' => $total, 'totalPages' => max(1, (int) ceil($total / $limit))],
            'stats' => $this->stats($eventIds),
            'channels' => $this->channels(null),
        ];
    }

    /** @return array<string, mixed> */
    public function show(Client $client, int $id): array
    {
        $campaign = Broadcast::query()->where('source', Broadcast::MESSAGE)->whereIn('event_id', $this->access->managedEventIds($client))
            ->with('event:id,vendor_id,client_id,name,start_date,start_time,venue_name,venue_address')->find($id)
            ?? throw ApiException::notFound('Message not found.');
        $deliveries = BroadcastRecipient::query()->where('broadcast_id', $campaign->id)
            ->with('participant:id,name,first_name,last_name,email,mobile,whatsapp,table_number')->orderBy('id')->limit(500)->get();

        $count = static fn (Closure $test): int => $deliveries->filter($test)->count();
        $shaped = [...$this->shape($campaign), 'delivery' => [
            'total' => $deliveries->count(),
            'delivered' => $count(static fn (BroadcastRecipient $d): bool => $d->status === 'delivered'),
            'failed' => $count(static fn (BroadcastRecipient $d): bool => $d->status === 'failed'),
            'opened' => $count(static fn (BroadcastRecipient $d): bool => $d->opened_at !== null),
            'queued' => $count(static fn (BroadcastRecipient $d): bool => $d->status === 'queued'),
        ]];

        return [
            'campaign' => $shaped,
            'preview' => MergeFields::render($campaign->body, $deliveries->first()?->participant, $campaign->event, $client->name),
            'recipients' => $deliveries->map(static fn (BroadcastRecipient $d): array => [
                'id' => $d->id, 'status' => $d->status, 'sent_at' => Dates::at($d->sent_at), 'delivered_at' => Dates::at($d->delivered_at),
                'opened_at' => Dates::at($d->opened_at), 'failed_reason' => $d->failed_reason,
                'guest' => $d->participant === null ? null : ['id' => $d->participant->id, 'name' => $d->participant->name, 'email' => $d->participant->getAttribute('email'), 'mobile' => $d->participant->mobile],
            ])->all(),
            'channel_state' => collect($this->channels($campaign->event))->firstWhere('channel', $campaign->channel),
        ];
    }

    /**
     * Scheduled campaigns that are due (every minute). The audience is the
     * snapshot taken when it was scheduled, not re-read (v1).
     */
    public function fireDue(): int
    {
        $fired = 0;
        $due = Broadcast::query()->where('source', Broadcast::MESSAGE)->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(25)->get();
        foreach ($due as $campaign) {
            // Claimed by one worker only.
            if (Broadcast::query()->whereKey($campaign->id)->where('status', 'scheduled')->update(['status' => 'sending']) === 0) {
                continue;
            }
            $fired++;
            try {
                $event = Event::query()->withoutGlobalScopes()->find($campaign->event_id)
                    ?? throw ApiException::notFound('The event of this message no longer exists.');
                $campaign->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
                BroadcastRecipient::query()->where('broadcast_id', $campaign->id)->update(['sent_at' => now()]);
                $state = collect($this->channels($event))->firstWhere('channel', $campaign->channel);
                if ($state['enabled'] ?? false) {
                    $this->deliver($campaign, $event);
                }
            } catch (Throwable $e) {
                report($e);
                $campaign->forceFill(['status' => 'failed', 'failed_reason' => mb_substr($e->getMessage(), 0, 255)])->save();
            }
        }

        return $fired;
    }

    /**
     * Delivery history rows for Events (RSVP details, guest profile, analytics).
     *
     * @param  Builder<BroadcastRecipient>  $query
     * @return list<array<string, mixed>>
     */
    public function log(Builder $query): array
    {
        return $query->where(static fn (Builder $q) => $q->whereNull('broadcast_id')
            ->orWhereIn('broadcast_id', Broadcast::query()->withTrashed()->where('source', Broadcast::MESSAGE)->select('id')))
            ->with(['participant:id,event_id', 'participant.event' => static fn ($q) => $q->withoutGlobalScopes()->select(['id', 'name'])])
            ->orderBy('id')->get()
            ->map(static fn (BroadcastRecipient $m): array => [
                'id' => $m->id, 'participant_id' => $m->participant_id, 'event_id' => $m->event_id, 'channel' => $m->channel,
                'kind' => $m->kind, 'status' => $m->status, 'sender' => $m->sender, 'sender_client_id' => $m->sender_client_id,
                'sent_at' => Dates::at($m->sent_at), 'delivered_at' => Dates::at($m->delivered_at), 'opened_at' => Dates::at($m->opened_at),
                'clicked_at' => Dates::at($m->clicked_at), 'created_at' => Dates::at($m->created_at),
                'event' => $m->participant?->event === null ? null : ['id' => $m->participant->event->id, 'name' => $m->participant->event->name],
            ])->all();
    }

    /** @return array<string, mixed> */
    private function shape(Broadcast $b): array
    {
        $event = $b->relationLoaded('event') ? $b->event : null;

        return [
            'id' => $b->id, 'subject' => $b->title, 'body' => $b->body, 'channel' => $b->channel,
            'channel_label' => self::LABELS[(string) $b->channel] ?? $b->channel, 'kind' => $b->kind, 'audience' => $b->audience,
            'group_ids' => $b->group_ids, 'guest_ids' => $b->participant_ids, 'recipients_count' => $b->recipients_count,
            'status' => $b->status, 'scheduled_at' => Dates::at($b->scheduled_at), 'sent_at' => Dates::at($b->sent_at),
            'failed_reason' => $b->failed_reason, 'created_at' => Dates::at($b->created_at),
            'event' => $event === null ? null : ['id' => $event->id, 'name' => $event->name, 'start_date' => Dates::day($event->start_date)],
            'delivery' => null,
        ];
    }

    /**
     * Push now, e-mail through the queue; WhatsApp stays recorded.
     *
     * @return array{delivered: int, failed: int}|null
     */
    private function deliver(Broadcast $campaign, Event $event): ?array
    {
        if ($campaign->channel === 'email') {
            DeliverEmailBroadcast::dispatch($campaign->id);

            return null;
        }
        if ($campaign->channel !== 'push') {
            return null;
        }

        $recipients = BroadcastRecipient::query()->where('broadcast_id', $campaign->id)->get(['id', 'participant_id', 'client_id']);
        $accounts = $recipients->pluck('client_id')->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        $outcomes = $this->push->send($event, $accounts, [
            'title' => $campaign->title, 'body' => (string) $campaign->body, 'image_url' => $campaign->image_url,
            'click_action' => $campaign->click_action, 'deep_link' => $campaign->deep_link,
            'data' => [...($campaign->data_payload ?? []), 'campaign_id' => $campaign->id, 'event_id' => $event->id],
            'options' => $campaign->push_options ?? [],
        ]);

        $delivered = [];
        $failed = [];
        foreach ($recipients as $r) {
            $outcome = $r->client_id === null ? null : ($outcomes[$r->client_id] ?? null);
            if ($outcome !== null && $outcome['ok']) {
                $delivered[] = $r->id;
            } else {
                $failed[mb_substr($outcome['reason'] ?? 'No active device for this guest', 0, 255)][] = $r->id;
            }
        }
        $now = now();
        if ($delivered !== []) {
            BroadcastRecipient::query()->whereKey($delivered)->update(['status' => 'sent', 'sent_at' => $now, 'delivered_at' => $now]);
        }
        foreach ($failed as $reason => $ids) {
            BroadcastRecipient::query()->whereKey($ids)->update(['status' => 'failed', 'failed_reason' => $reason]);
        }
        $failedCount = array_sum(array_map('count', $failed));
        $campaign->forceFill([
            'status' => $delivered !== [] ? 'sent' : 'failed', 'sent_at' => $now,
            'delivered_count' => count($delivered), 'failed_count' => $failedCount,
            'failed_reason' => $delivered !== [] ? null : (string) (array_key_first($failed) ?? 'No notification could be delivered'),
        ])->save();

        // The app's bell, for everyone the push was addressed to (read state is ours, not Firebase's).
        $this->feed->recordMany(array_map(static fn (int $clientId): ClientNotice => new ClientNotice(
            $clientId, 'push_notification', $campaign->title, (string) $campaign->body,
            $campaign->deep_link ?: "/dashboard/events/{$event->id}", $event->id, null, ['campaign_id' => $campaign->id, 'channel' => 'push'],
        ), $accounts));

        return ['delivered' => count($delivered), 'failed' => $failedCount];
    }

    /**
     * Who a send reaches: the selection, minus declined guests (the only
     * opt-out the schema has), minus those the channel cannot reach.
     *
     * @param  array<string, mixed>  $input
     * @return array{guests: Collection<int, Participant>, declined: int, eligible: Collection<int, Participant>, unreachable: Collection<int, Participant>, heads: int}
     */
    private function audience(Event $event, array $input, string $channel): array
    {
        $query = Participant::query()->where('event_id', $event->id);
        $audience = $input['audience'] ?? 'all';
        if ($audience === 'groups') {
            $ids = $this->ids($input['group_ids'] ?? []);
            if ($ids === []) {
                throw ApiException::badRequest('Choose at least one group.');
            }
            $query->whereIn('guest_id', Guest::query()->where('client_id', $event->client_id)->whereIn('group_id', $ids)->select('id'));
        } elseif ($audience === 'guests') {
            $ids = $this->ids($input['guest_ids'] ?? []);
            if ($ids === []) {
                throw ApiException::badRequest('Choose at least one guest.');
            }
            $query->whereKey($ids);
        }
        $guests = $query->orderBy('name')->get();

        $excludeDeclined = ($input['exclude_unsubscribed'] ?? true) !== false;
        $considered = $excludeDeclined ? $guests->where('rsvp_status', '!=', 'declined') : $guests;
        $pushable = $channel === 'push' ? $this->push->reachable($considered->pluck('client_id')->filter()->map(static fn ($id): int => (int) $id)->values()->all()) : [];
        $reachable = static fn (Participant $g): bool => match ($channel) {
            'email' => (string) $g->getAttribute('email') !== '',
            'push' => $g->client_id !== null && isset($pushable[$g->client_id]),
            'sms' => (string) $g->mobile !== '',
            default => (string) ($g->getAttribute('whatsapp') ?: $g->mobile) !== '',
        };

        return [
            'guests' => $guests,
            'declined' => $guests->count() - $considered->count(),
            'eligible' => $considered->filter($reachable)->values(),
            'unreachable' => $considered->reject($reachable)->values(),
            'heads' => (int) $guests->sum(static fn (Participant $g): int => $g->party_size ?: 1),
        ];
    }

    private function unreachableReason(int $count, string $channel): string
    {
        $has = $count === 1 ? ' has' : 's have';

        return $channel === 'push'
            ? "{$count} guest{$has} not installed the app and will be skipped. A guest becomes reachable once they join through the mobile app and allow notifications."
            : "{$count} guest{$has} no ".($channel === 'email' ? 'email address' : 'phone number').' on file and will be skipped.';
    }

    private function event(Client $client, mixed $eventId): Event
    {
        if (! is_numeric($eventId) || (int) $eventId <= 0) {
            throw ApiException::badRequest('Choose an event.');
        }
        if (! in_array((int) $eventId, $this->access->managedEventIds($client), true)) {
            throw ApiException::notFound('Event not found.');
        }

        return Event::query()->findOrFail((int) $eventId);
    }

    /** A schedule in the past is refused, never sent at once (v1). */
    private function scheduledAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $at = Carbon::parse((string) $value);
        } catch (Throwable) {
            throw ApiException::badRequest('That is not a valid date and time.');
        }
        if ($at->lt(now()->subMinute())) {
            throw ApiException::badRequest('That time has already passed. Choose a later time.');
        }

        return $at;
    }

    /**
     * Push extras, normalised here because they end up in the FCM request,
     * where a bad TTL or badge fails with an error naming no cause (v1).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function pushOptions(array $input): array
    {
        $raw = is_array($input['push_options'] ?? null) ? $input['push_options'] : [];
        $click = in_array($input['click_action'] ?? null, ['open_app', 'deep_link', 'custom'], true) ? (string) $input['click_action'] : 'open_app';
        $deepLink = $click === 'open_app' ? null : (mb_substr(trim($this->text($input['deep_link'] ?? null)), 0, 500) ?: null);

        $source = $input['data_payload'] ?? [];
        if (is_array($source) && array_is_list($source)) {
            $source = collect($source)->filter(static fn ($r): bool => is_array($r))->mapWithKeys(static fn (array $r): array => [(string) ($r['key'] ?? '') => $r['value'] ?? null])->all();
        }
        $data = [];
        foreach (is_array($source) ? $source : [] as $key => $value) {
            $key = trim((string) $key);
            if ($key === '' || $value === null || $value === '' || in_array($key, ['from', 'notification', 'message_type'], true)) {
                continue;
            }
            $data[mb_substr($key, 0, 60)] = mb_substr(is_scalar($value) ? (string) $value : (string) json_encode($value), 0, 500);
        }

        $badge = in_array($raw['badge_mode'] ?? null, ['increment', 'set', 'clear'], true) ? (string) $raw['badge_mode'] : 'increment';
        $ttl = (int) ($raw['ttl_seconds'] ?? 0);

        return [
            'image_url' => mb_substr(trim($this->text($input['image_url'] ?? null)), 0, 500) ?: null,
            'click_action' => $click,
            'deep_link' => $deepLink,
            'data_payload' => $data === [] ? null : $data,
            'push_options' => [
                'badge_enabled' => ($raw['badge_enabled'] ?? true) !== false,
                'badge_mode' => $badge,
                'badge_value' => $badge === 'set' ? max(0, min(9999, (int) ($raw['badge_value'] ?? 0))) : null,
                'priority' => ($raw['priority'] ?? null) === 'normal' ? 'normal' : 'high',
                'ttl_seconds' => $ttl > 0 ? min(self::MAX_TTL, $ttl) : 86_400,
                'send_to_offline' => ($raw['send_to_offline'] ?? true) !== false,
                'collapse_key' => mb_substr(trim((string) ($raw['collapse_key'] ?? '')), 0, 60) ?: null,
                'content_available' => (bool) ($raw['content_available'] ?? false),
                'restricted_package_name' => mb_substr(trim((string) ($raw['restricted_package_name'] ?? '')), 0, 120) ?: null,
            ],
        ];
    }

    /** @param list<int> $eventIds
     * @return array<string, mixed> */
    private function stats(array $eventIds): array
    {
        $rows = BroadcastRecipient::query()->whereIn('event_id', $eventIds)
            ->where(static fn (Builder $q) => $q->whereNull('broadcast_id')
                ->orWhereIn('broadcast_id', Broadcast::query()->withTrashed()->where('source', Broadcast::MESSAGE)->select('id')))
            ->groupBy('channel')
            ->selectRaw("channel, COUNT(*) as total, SUM(status IN ('sent','delivered')) as sent, SUM(status = 'delivered') as delivered, SUM(status = 'failed') as failed, SUM(status = 'queued') as queued, SUM(opened_at IS NOT NULL) as opened, SUM(clicked_at IS NOT NULL) as clicked")
            ->get();

        $byChannel = [];
        foreach ($rows as $r) {
            $byChannel[(string) $r->getAttribute('channel')] = array_map('intval', $r->only(['total', 'sent', 'delivered', 'failed', 'queued', 'opened', 'clicked']));
        }
        $grand = (int) $rows->sum('total');
        // Every offered channel gets a tile; an old SMS campaign keeps its own.
        foreach (array_unique([...self::CHANNELS, ...array_keys($byChannel)]) as $channel) {
            $byChannel[$channel] ??= ['total' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'queued' => 0, 'opened' => 0, 'clicked' => 0];
            $byChannel[$channel]['share'] = $grand > 0 ? round($byChannel[$channel]['total'] / $grand * 100, 1) : 0;
        }

        return ['total' => $grand, 'by_channel' => $byChannel];
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', array_filter(is_array($value) ? $value : [], 'is_numeric'))));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
