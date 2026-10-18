<?php

declare(strict_types=1);

namespace App\Modules\Events\Actions;

use App\Core\Exceptions\ApiException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventSetting;
use Illuminate\Support\Carbon;

/**
 * An event's RSVP form and participant-directory settings (v1
 * clientRsvpSettings / clientParticipantSettings). Stored sections fill in
 * defaults on read, so an event never saved still answers sensibly.
 */
final class EventSettings
{
    public const RESPONSE_OPTIONS = ['yes', 'no', 'maybe'];

    private const RSVP_FLAGS = ['enabled' => true, 'allow_guest_count' => true, 'allow_food_preference' => false, 'allow_special_requests' => true, 'allow_relationship' => false];

    private const PARTICIPANT_FLAGS = ['profile_screen' => true, 'profile_photo' => true, 'relationship' => true, 'attendance_status' => true];

    /** @return array<string, mixed> */
    public function rsvp(Event $event): array
    {
        $stored = $this->row($event)->rsvp ?? [];
        $options = is_array($stored['response_options'] ?? null) ? array_values(array_intersect(self::RESPONSE_OPTIONS, $stored['response_options'])) : [];
        $deadline = $this->isDate($stored['deadline'] ?? null) ? $stored['deadline'] : null;
        $settings = [];
        foreach (self::RSVP_FLAGS as $flag => $default) {
            $settings[$flag] = $this->bool($stored[$flag] ?? null, $default);
        }

        return [
            'enabled' => $settings['enabled'],
            'response_options' => $options !== [] ? $options : self::RESPONSE_OPTIONS,
            'deadline' => $deadline,
            'effective_deadline' => $deadline ?? $event->end_date?->format('Y-m-d'),
            'allow_guest_count' => $settings['allow_guest_count'],
            'allow_food_preference' => $settings['allow_food_preference'],
            'allow_special_requests' => $settings['allow_special_requests'],
            'allow_relationship' => $settings['allow_relationship'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateRsvp(Event $event, array $input): array
    {
        $next = $this->rsvp($event);
        unset($next['effective_deadline']);
        foreach (array_keys(self::RSVP_FLAGS) as $flag) {
            if (array_key_exists($flag, $input)) {
                $next[$flag] = $this->bool($input[$flag], $next[$flag]);
            }
        }
        if (array_key_exists('response_options', $input)) {
            $picked = is_array($input['response_options']) ? array_values(array_intersect(self::RESPONSE_OPTIONS, array_map('strval', $input['response_options']))) : [];
            $next['response_options'] = $picked !== [] ? $picked : throw ApiException::badRequest('Keep at least one response option.');
        }
        if (array_key_exists('deadline', $input)) {
            $value = $input['deadline'];
            if ($value === null || $value === '') {
                $next['deadline'] = null;
            } else {
                $value = substr(is_scalar($value) ? (string) $value : '', 0, 10);
                if (! $this->isDate($value)) {
                    throw ApiException::badRequest('Please choose a valid response deadline.');
                }
                if ($event->end_date !== null && $value > $event->end_date->format('Y-m-d')) {
                    throw ApiException::badRequest('The response deadline cannot be after the event ends.');
                }
                $next['deadline'] = $value;
            }
        }
        $this->row($event)->fill(['rsvp' => $next])->save();

        return $this->rsvpView($event);
    }

    /** @return array<string, mixed> GET/PUT /events/{id}/rsvp-settings */
    public function rsvpView(Event $event): array
    {
        return ['event_id' => $event->id, 'event_start_date' => $event->start_date?->format('Y-m-d'), 'event_end_date' => $event->end_date?->format('Y-m-d'), 'settings' => $this->rsvp($event)];
    }

    public function rsvpDeadlinePassed(Event $event): bool
    {
        $deadline = $this->rsvp($event)['effective_deadline'];

        return $deadline !== null && now()->gt(Carbon::parse($deadline)->endOfDay());
    }

    /** @return array<string, bool> */
    public function participant(Event $event): array
    {
        $stored = $this->row($event)->participant ?? [];
        $settings = [];
        foreach (self::PARTICIPANT_FLAGS as $flag => $default) {
            $settings[$flag] = $this->bool($stored[$flag] ?? null, $default);
        }

        // A row without a name cannot be identified, so name is always shown.
        return ['profile_screen' => $settings['profile_screen'], 'profile_photo' => $settings['profile_photo'], 'name' => true,
            'relationship' => $settings['relationship'], 'attendance_status' => $settings['attendance_status']];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public function updateParticipant(Event $event, array $input): array
    {
        $current = $this->participant($event);
        $next = $current;
        foreach (array_keys(self::PARTICIPANT_FLAGS) as $flag) {
            $next[$flag] = $this->bool($input[$flag] ?? null, $current[$flag]);
        }
        $next['name'] = true;
        $this->row($event)->fill(['participant' => $next])->save();

        return $next;
    }

    private function row(Event $event): EventSetting
    {
        $event->loadMissing('settings');

        return $event->settings ?? $event->setRelation('settings', EventSetting::query()->firstOrNew(['event_id' => $event->id]))->settings;
    }

    private function bool(mixed $value, bool $fallback): bool
    {
        return $value === null ? $fallback : in_array($value, [true, 1, '1', 'true'], true);
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
