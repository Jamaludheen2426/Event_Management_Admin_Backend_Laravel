<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;

/**
 * {{first_name}}-style placeholders in a message (v1 clientMessage render).
 * An unknown token is left as written, so a typo shows in the preview.
 */
final class MergeFields
{
    public const FIELDS = [
        ['token' => 'first_name', 'label' => 'Guest first name', 'example' => 'Arjun'],
        ['token' => 'last_name', 'label' => 'Guest last name', 'example' => 'Sharma'],
        ['token' => 'full_name', 'label' => 'Guest full name', 'example' => 'Arjun Sharma'],
        ['token' => 'event_name', 'label' => 'Event name', 'example' => 'Our Special Wedding'],
        ['token' => 'event_date', 'label' => 'Event date', 'example' => '24 May 2025'],
        ['token' => 'event_time', 'label' => 'Event time', 'example' => '06:00 PM'],
        ['token' => 'venue_name', 'label' => 'Venue', 'example' => 'The Grand Palace'],
        ['token' => 'venue_address', 'label' => 'Venue address', 'example' => 'Delhi'],
        ['token' => 'host_name', 'label' => 'Host name', 'example' => 'Rohan Mehta'],
        ['token' => 'table_number', 'label' => 'Table number', 'example' => '12'],
        ['token' => 'rsvp_link', 'label' => 'RSVP link', 'example' => 'https://…/rsvp/…'],
    ];

    public static function render(?string $text, ?Participant $guest, ?Event $event, ?string $hostName): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $words = explode(' ', (string) $guest?->name);
        $values = [
            'first_name' => (string) ($guest?->getAttribute('first_name') ?: $words[0]),
            'last_name' => (string) ($guest?->getAttribute('last_name') ?: implode(' ', array_slice($words, 1))),
            'full_name' => (string) $guest?->name,
            'table_number' => (string) $guest?->getAttribute('table_number'),
            'event_name' => (string) $event?->name,
            'event_date' => $event?->start_date === null ? '' : $event->start_date->format('j M Y'),
            'event_time' => self::time($event?->start_time),
            'venue_name' => (string) $event?->getAttribute('venue_name'),
            'venue_address' => (string) $event?->getAttribute('venue_address'),
            'host_name' => (string) ($hostName ?: $event?->getAttribute('host_one')),
            'rsvp_link' => $event === null ? '' : "/rsvp/{$event->id}",
        ];

        return (string) preg_replace_callback('/\{\{?\s*([a-z_]+)\s*\}?\}/i', static function (array $m) use ($values): string {
            return $values[strtolower($m[1])] ?? $m[0];
        }, $text);
    }

    /** "18:30:00" → "06:30 PM" */
    private static function time(?string $value): string
    {
        if ($value === null || preg_match('/^(\d{2}):(\d{2})/', $value, $m) !== 1) {
            return '';
        }
        $hour = (int) $m[1];

        return sprintf('%02d:%s %s', $hour % 12 === 0 ? 12 : $hour % 12, $m[2], $hour >= 12 ? 'PM' : 'AM');
    }
}
