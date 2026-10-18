<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use App\Modules\Events\Support\MessageLog;
use Illuminate\Support\Carbon;

/**
 * The guest and message side of the analytics screen (v1
 * clientAnalytics.service): totals with change against the previous period,
 * RSVP breakdown and daily trend, messages by channel, how guests arrived,
 * top events.
 */
final class GuestAnalytics
{
    private const CHANNELS = ['whatsapp', 'email', 'sms'];

    private const SOURCES = ['whatsapp', 'email', 'sms', 'manual', 'import'];

    private const SLICES = ['attending', 'not_attending', 'maybe', 'no_response'];

    public function __construct(private readonly MessageLog $messages) {}

    /** @return array<string, mixed> */
    public function report(Client $host, int $days): array
    {
        $days = min(365, max(7, $days));
        $today = today();
        $periodStart = $today->copy()->subDays($days - 1);
        $previousStart = $today->copy()->subDays($days * 2 - 1);
        $inPeriod = static fn ($at): bool => $at !== null && Carbon::parse($at)->gte($periodStart);
        $inPrevious = static fn ($at): bool => $at !== null && Carbon::parse($at)->gte($previousStart) && Carbon::parse($at)->lt($periodStart);

        $events = Event::query()->where('client_id', $host->id)->with('category:id,name')->get(['id', 'name', 'status', 'start_date', 'end_date', 'theme_id', 'created_at', 'event_category_id']);
        $guests = Participant::query()->whereIn('event_id', $events->pluck('id'))
            ->get(['id', 'event_id', 'party_size', 'rsvp_status', 'response_type', 'invite_source', 'invited_at', 'responded_at', 'created_at']);
        $messages = $this->messages->forClient($host->id);

        $slice = static fn (string $status): string => match ($status) {
            'accepted' => 'attending', 'declined' => 'not_attending', 'pending' => 'maybe', default => 'no_response',
        };
        $rate = static fn (int|float $n, int|float $d): ?float => $d > 0 ? round($n / $d * 100, 1) : null;
        $delta = static fn (int|float $now, int|float $before): ?float => $before > 0 ? round(($now - $before) / $before * 100, 1) : null;

        $totalHeads = $invited = $responded = 0;
        $headsNow = $headsBefore = $invitedNow = $invitedBefore = $respondedNow = $respondedBefore = 0;
        $rsvp = array_fill_keys(self::SLICES, 0);
        $sources = array_fill_keys(self::SOURCES, 0);
        $perEvent = [];
        foreach ($guests as $g) {
            $heads = $g->party_size ?: 1;
            $wasInvited = $g->getAttribute('invited_at') !== null || $g->rsvp_status !== 'not_responded';
            $totalHeads += $heads;
            $rsvp[$slice($g->rsvp_status)]++;
            $sources[$g->invite_source] = ($sources[$g->invite_source] ?? 0) + 1;
            $invited += (int) $wasInvited;
            $responded += (int) ($g->responded_at !== null);
            if ($inPeriod($g->getAttribute('created_at'))) {
                $headsNow += $heads;
            } elseif ($inPrevious($g->getAttribute('created_at'))) {
                $headsBefore += $heads;
            }
            if ($inPeriod($g->getAttribute('invited_at'))) {
                $invitedNow++;
            } elseif ($inPrevious($g->getAttribute('invited_at'))) {
                $invitedBefore++;
            }
            if ($inPeriod($g->responded_at)) {
                $respondedNow++;
            } elseif ($inPrevious($g->responded_at)) {
                $respondedBefore++;
            }

            $perEvent[$g->event_id] ??= ['guests' => 0, 'invited' => 0, 'attending' => 0, 'responded' => 0];
            $perEvent[$g->event_id]['guests'] += $heads;
            $perEvent[$g->event_id]['invited'] += (int) $wasInvited;
            $perEvent[$g->event_id]['attending'] += (int) ($g->rsvp_status === 'accepted');
            $perEvent[$g->event_id]['responded'] += (int) ($g->responded_at !== null);
        }

        $byChannel = array_fill_keys(self::CHANNELS, ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'failed' => 0]);
        $all = ['delivered' => 0, 'opened' => 0, 'clicked' => 0];
        $sent = 0;
        $counts = ['sent' => [0, 0], 'delivered' => [0, 0], 'opened' => [0, 0], 'clicked' => [0, 0]];
        foreach ($messages as $m) {
            $channel = $m['channel'] ?? null;
            if (! is_string($channel) || ! isset($byChannel[$channel]) || ($m['status'] ?? null) === 'queued') {
                continue;
            }
            $sent++;
            $byChannel[$channel]['sent']++;
            $byChannel[$channel]['failed'] += (int) (($m['status'] ?? null) === 'failed');
            foreach (['delivered', 'opened', 'clicked'] as $field) {
                if (! empty($m[$field.'_at'])) {
                    $all[$field]++;
                    $byChannel[$channel][$field]++;
                }
            }
            foreach (['sent', 'delivered', 'opened', 'clicked'] as $field) {
                $at = $m[$field.'_at'] ?? null;
                if ($inPeriod($at)) {
                    $counts[$field][0]++;
                } elseif ($inPrevious($at)) {
                    $counts[$field][1]++;
                }
            }
        }

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $trend[$d->format('Y-m-d')] = ['key' => $d->format('Y-m-d'), 'label' => $d->format('j M'), 'attending' => 0, 'not_attending' => 0, 'maybe' => 0, 'no_response' => 0];
        }
        foreach ($guests as $g) {
            $key = $g->responded_at?->format('Y-m-d');
            if ($key !== null && isset($trend[$key])) {
                $trend[$key][$slice($g->rsvp_status)]++;
            }
        }
        $silent = $guests->filter(static fn (Participant $g): bool => ($g->getAttribute('invited_at') !== null || $g->rsvp_status === 'invited') && $g->responded_at === null)->count();
        foreach ($trend as &$row) {
            $row['no_response'] = $silent;
        }
        unset($row);

        $total = $guests->count();
        $eventsById = $events->keyBy('id');
        $top = collect($perEvent)->map(static function (array $b, int $id) use ($eventsById, $rate): array {
            $e = $eventsById->get($id);

            return [
                'id' => $id, 'name' => $e->name ?? "Event #{$id}", 'start_date' => $e?->start_date?->format('Y-m-d'), 'theme_id' => $e?->theme_id,
                'category' => $e?->category?->name, 'guests' => $b['guests'], 'rsvp_rate' => $rate($b['attending'], $b['invited']), 'response_rate' => $rate($b['responded'], $b['invited']),
            ];
        })->sortByDesc('guests')->take(5)->values()->all();

        return [
            'period' => ['days' => $days, 'from' => $periodStart->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            'totals' => [
                'total_guests' => $totalHeads, 'total_rsvps' => $total, 'messages_sent' => $sent,
                'open_rate' => $rate($all['opened'], $all['delivered']), 'response_rate' => $rate($responded, $invited), 'click_rate' => $rate($all['clicked'], $all['delivered']),
            ],
            'deltas' => [
                'total_guests' => $delta($headsNow, $headsBefore),
                'total_rsvps' => $delta($respondedNow, $respondedBefore),
                'messages_sent' => $delta($counts['sent'][0], $counts['sent'][1]),
                'open_rate' => $delta($rate($counts['opened'][0], $counts['delivered'][0]) ?? 0, $rate($counts['opened'][1], $counts['delivered'][1]) ?? 0),
                'response_rate' => $delta($rate($respondedNow, $invitedNow) ?? 0, $rate($respondedBefore, $invitedBefore) ?? 0),
                'click_rate' => $delta($rate($counts['clicked'][0], $counts['delivered'][0]) ?? 0, $rate($counts['clicked'][1], $counts['delivered'][1]) ?? 0),
            ],
            'rsvp_breakdown' => array_map(static fn (string $k): array => ['key' => $k, 'count' => $rsvp[$k], 'percent' => $rate($rsvp[$k], $total) ?? 0], self::SLICES),
            'rsvp_trend' => array_values($trend),
            'messages_by_channel' => array_map(static fn (string $k): array => [
                'key' => $k, 'sent' => $byChannel[$k]['sent'], 'delivered' => $byChannel[$k]['delivered'], 'failed' => $byChannel[$k]['failed'],
                'percent' => $rate($byChannel[$k]['sent'], $sent) ?? 0, 'delivery_rate' => $rate($byChannel[$k]['delivered'], $byChannel[$k]['sent']),
                'open_rate' => $k !== 'sms' ? $rate($byChannel[$k]['opened'], $byChannel[$k]['delivered']) : null,
                'click_rate' => $k !== 'sms' ? $rate($byChannel[$k]['clicked'], $byChannel[$k]['delivered']) : null,
            ], self::CHANNELS),
            'engagement_by_source' => array_map(static fn (string $k): array => ['key' => $k, 'count' => $sources[$k], 'percent' => $rate($sources[$k], $total) ?? 0], self::SOURCES),
            'top_events' => $top,
        ];
    }
}
