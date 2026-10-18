<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Exceptions\ApiException;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientNotificationPref;
use App\Modules\Clients\Models\ClientPreference;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The client portal's Settings page (v1 clientPreferences.service): display
 * preferences, quiet hours and the notification switches.
 *
 * The notification catalogue is code, not data: a switch exists only for a
 * notification the system can actually send. Unset switches answer the
 * catalogue default; only changed ones are stored.
 */
final class ClientSettings
{
    private const EMAIL_FREQUENCIES = [
        ['value' => 'instant', 'label' => 'Instant'],
        ['value' => 'daily_digest', 'label' => 'Daily Digest'],
        ['value' => 'weekly_digest', 'label' => 'Weekly Digest'],
    ];

    private const REMINDER_FREQUENCIES = [
        ['value' => '1h_before', 'label' => '1 hour before'],
        ['value' => '24h_before', 'label' => '24 hours before'],
        ['value' => '48h_before', 'label' => '2 days before'],
        ['value' => '1w_before', 'label' => '1 week before'],
    ];

    public const OPTIONS = [
        'language_code' => [['value' => 'en', 'label' => 'English']],
        'date_format' => [
            ['value' => 'DD/MM/YYYY', 'label' => '31/12/2026 (DD/MM/YYYY)'],
            ['value' => 'MM/DD/YYYY', 'label' => '12/31/2026 (MM/DD/YYYY)'],
            ['value' => 'YYYY-MM-DD', 'label' => '2026-12-31 (YYYY-MM-DD)'],
            ['value' => 'DD MMM YYYY', 'label' => '31 Dec 2026'],
            ['value' => 'MMM DD, YYYY', 'label' => 'Dec 31, 2026'],
        ],
        'theme' => [
            ['value' => 'light', 'label' => 'Light'],
            ['value' => 'dark', 'label' => 'Dark'],
            ['value' => 'system', 'label' => 'System'],
        ],
        'default_landing' => [
            ['value' => 'dashboard', 'label' => 'Dashboard', 'path' => '/dashboard'],
            ['value' => 'events', 'label' => 'My Events', 'path' => '/dashboard/events'],
            ['value' => 'guests', 'label' => 'Guests', 'path' => '/dashboard/guests'],
            ['value' => 'templates', 'label' => 'Templates', 'path' => '/dashboard/templates'],
        ],
        'items_per_page' => [
            ['value' => 10, 'label' => '10'],
            ['value' => 20, 'label' => '20'],
            ['value' => 50, 'label' => '50'],
            ['value' => 100, 'label' => '100'],
        ],
    ];

    /** Which preferences the portal actually applies today (the rest are only stored). */
    private const APPLIED = [
        'language_code' => false, 'date_format' => true, 'time_zone' => true, 'theme' => true, 'default_landing' => false,
        'items_per_page' => false, 'compact_mode' => false, 'auto_save' => false, 'show_tips' => false,
    ];

    private const BOOLEANS = ['compact_mode', 'auto_save', 'show_tips', 'emails_disabled', 'in_app_disabled'];

    private const DATES = ['dnd_starts_at', 'dnd_ends_at'];

    /** @var list<Closure(Client): bool> */
    private array $emailDelivery = [];

    /**
     * Messaging tells whether an email provider is configured for this client's company.
     *
     * @param  Closure(Client): bool  $check
     */
    public function emailDeliveryUsing(Closure $check): void
    {
        $this->emailDelivery[] = $check;
    }

    /** @return array<string, mixed> */
    public function get(Client $client): array
    {
        $prefs = $this->preferences($client);
        $rows = ClientNotificationPref::query()->where('client_id', $client->id)->get();
        $iso = static fn (?Carbon $d): ?string => $d?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'preferences' => [
                ...$prefs->only(['id', 'client_id', 'language_code', 'date_format', 'time_zone', 'theme', 'default_landing',
                    'items_per_page', 'compact_mode', 'auto_save', 'show_tips', 'emails_disabled', 'in_app_disabled']),
                'website_client_id' => $client->id,
                'dnd_starts_at' => $iso($prefs->dnd_starts_at),
                'dnd_ends_at' => $iso($prefs->dnd_ends_at),
            ],
            'notifications' => [
                'email' => $this->merge('email', $rows->all()),
                'in_app' => $this->merge('in_app', $rows->all()),
            ],
            'options' => self::OPTIONS,
            'applied' => self::APPLIED,
            'defaults' => ClientPreference::DEFAULTS,
            'delivery' => $this->delivery($client),
            'dnd_active' => $this->dndActive($prefs),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updatePreferences(Client $client, array $input): array
    {
        $patch = [];
        foreach ([...array_keys(self::APPLIED), ...self::BOOLEANS, ...self::DATES] as $key) {
            if (! array_key_exists($key, $input) || array_key_exists($key, $patch)) {
                continue;
            }
            $value = $input[$key];
            if (in_array($key, self::BOOLEANS, true)) {
                $patch[$key] = in_array($value, [true, 1, '1', 'true'], true);
            } elseif (in_array($key, self::DATES, true)) {
                $patch[$key] = $value === null || $value === '' ? null : $this->date($value);
            } elseif ($key === 'time_zone') {
                if (! is_string($value) || ! in_array($value, timezone_identifiers_list(), true)) {
                    throw ApiException::badRequest('"'.(is_scalar($value) ? $value : '').'" is not an accepted value for time_zone.');
                }
                $patch[$key] = $value;
            } else {
                $allowed = collect(self::OPTIONS[$key])->contains(static fn (array $o): bool => (string) $o['value'] === (is_scalar($value) ? (string) $value : ''));
                if (! $allowed) {
                    throw ApiException::badRequest('"'.(is_scalar($value) ? $value : '').'" is not an accepted value for '.$key.'.');
                }
                $patch[$key] = $key === 'items_per_page' ? (int) $value : $value;
            }
        }
        if ($patch === []) {
            throw ApiException::badRequest('There is nothing to update.');
        }

        $prefs = $this->preferences($client);
        $starts = array_key_exists('dnd_starts_at', $patch) ? $patch['dnd_starts_at'] : $prefs->dnd_starts_at;
        $ends = array_key_exists('dnd_ends_at', $patch) ? $patch['dnd_ends_at'] : $prefs->dnd_ends_at;
        if ($starts !== null && $ends !== null && $ends <= $starts) {
            throw ApiException::badRequest('Do Not Disturb must end after it starts.');
        }

        $prefs->fill($patch)->save();

        return $this->get($client);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateNotifications(Client $client, array $input): array
    {
        $items = $input['items'] ?? null;
        if (! is_array($items) || $items === []) {
            throw ApiException::badRequest('No notification settings were sent.');
        }

        $rows = [];
        foreach ($items as $item) {
            $channel = is_array($item) ? ($item['channel'] ?? null) : null;
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            if (! in_array($channel, ['email', 'in_app'], true)) {
                throw ApiException::badRequest('"'.(is_scalar($channel) ? $channel : '').'" is not a notification channel.');
            }
            $known = $this->known($channel)[is_string($type) ? $type : ''] ?? null;
            if ($known === null) {
                throw ApiException::badRequest('"'.(is_scalar($type) ? $type : '')."\" is not a {$channel} notification in this system.");
            }

            $row = ['enabled' => ! array_key_exists('enabled', $item) || (bool) $item['enabled'], 'frequency' => null, 'sound' => null];
            if ($channel === 'email') {
                $frequency = $item['frequency'] ?? $known['default_frequency'] ?? 'instant';
                $allowed = array_column($known['frequencies'] ?? self::EMAIL_FREQUENCIES, 'value');
                if (! in_array($frequency, $allowed, true)) {
                    throw ApiException::badRequest('"'.(is_scalar($frequency) ? $frequency : '')."\" is not an accepted frequency for {$type}.");
                }
                $row['frequency'] = $frequency;
            } else {
                $row['sound'] = array_key_exists('sound', $item) ? (bool) $item['sound'] : (bool) ($known['default_sound'] ?? false);
            }
            $rows[] = ['client_id' => $client->id, 'channel' => $channel, 'type' => $type, ...$row, 'created_at' => now(), 'updated_at' => now()];
        }

        ClientNotificationPref::query()->upsert($rows, ['client_id', 'channel', 'type'], ['enabled', 'frequency', 'sound', 'updated_at']);

        return $this->get($client);
    }

    private function preferences(Client $client): ClientPreference
    {
        return ClientPreference::query()->firstOrCreate(['client_id' => $client->id], ClientPreference::DEFAULTS);
    }

    /**
     * @param  list<ClientNotificationPref>  $rows
     * @return list<array<string, mixed>>
     */
    private function merge(string $channel, array $rows): array
    {
        $stored = collect($rows)->where('channel', $channel)->keyBy('type');

        return array_map(static fn (array $group): array => [
            'group' => $group['group'],
            'types' => array_map(static function (array $t) use ($stored, $channel): array {
                /** @var ClientNotificationPref|null $row */
                $row = $stored->get($t['type']);

                return [
                    'type' => $t['type'],
                    'label' => $t['label'],
                    'description' => $t['description'],
                    ...(isset($t['frequencies']) ? ['frequencies' => $t['frequencies']] : []),
                    'enabled' => $row !== null ? $row->enabled : ($t['default_enabled'] ?? true),
                    ...($channel === 'email'
                        ? ['frequency' => $row->frequency ?? $t['default_frequency'] ?? 'instant']
                        : ['sound' => $row !== null ? (bool) $row->sound : ($t['default_sound'] ?? false)]),
                    'is_set' => $row !== null,
                ];
            }, $group['types']),
        ], $this->catalogue()[$channel]);
    }

    /** @return array<string, array<string, mixed>> */
    private function known(string $channel): array
    {
        return collect($this->catalogue()[$channel])->flatMap(static fn (array $g) => $g['types'])->keyBy('type')->all();
    }

    /** @return array<string, list<array{group: string, types: list<array<string, mixed>>}>> */
    private function catalogue(): array
    {
        $email = static fn (string $type, string $label, string $description, string $frequency, array $extra = []): array => [
            'type' => $type, 'label' => $label, 'description' => $description,
            'frequencies' => self::EMAIL_FREQUENCIES, 'default_frequency' => $frequency, ...$extra,
        ];
        $inApp = static fn (string $type, string $label, string $description, bool $sound = false): array => [
            'type' => $type, 'label' => $label, 'description' => $description, ...($sound ? ['default_sound' => true] : []),
        ];

        return [
            'email' => [
                ['group' => 'Event Activity', 'types' => [
                    $email('new_rsvp', 'New RSVP', 'When someone responds to your event.', 'instant'),
                    $email('event_reminder', 'Event Reminder', 'A reminder before your event starts.', '24h_before', ['frequencies' => self::REMINDER_FREQUENCIES]),
                    $email('event_updates', 'Event Updates', 'When details of your event change.', 'instant'),
                ]],
                ['group' => 'Guests', 'types' => [
                    $email('new_guest', 'New Guest Added', 'When a guest is added to your event.', 'daily_digest'),
                ]],
                ['group' => 'Account & Billing', 'types' => [
                    $email('billing_payments', 'Billing & Payments', 'Invoices, payments and refunds.', 'instant'),
                    $email('plan_subscription', 'Plan & Subscription', 'Changes to your plan, renewals and cancellations.', 'instant'),
                    $email('account_security', 'Account Security', 'Password changes and account activity.', 'instant'),
                    $email('new_login', 'New Sign-in Alerts', 'When your account is signed in to on a new device.', 'instant'),
                ]],
                ['group' => 'From us', 'types' => [
                    $email('product_updates', 'Product Updates', 'New features and improvements.', 'weekly_digest'),
                    $email('marketing_tips', 'Offers & Tips', 'Occasional offers and tips for getting more from your events.', 'weekly_digest', ['default_enabled' => false]),
                ]],
            ],
            'in_app' => [
                ['group' => 'Events', 'types' => [
                    $inApp('event_created', 'Event Created', 'When a new event is created.'),
                    $inApp('event_updated', 'Event Updated', 'When an event is changed.'),
                    $inApp('event_reminder', 'Event Reminder', 'Before an event starts.', true),
                ]],
                ['group' => 'Guests & RSVPs', 'types' => [
                    $inApp('new_rsvp', 'New RSVP', 'When someone responds to your event.', true),
                    $inApp('new_guest', 'New Guest Added', 'When a guest is added to your event.'),
                    $inApp('rsvp_updated', 'RSVP Updated', 'When a guest changes their response.'),
                ]],
                ['group' => 'System & Account', 'types' => [
                    $inApp('system_announcements', 'System Announcements', 'Important updates about the platform.'),
                    $inApp('account_alerts', 'Account Alerts', 'Security and account related alerts.', true),
                ]],
            ],
        ];
    }

    /** @return array<string, array{enabled: bool, reason: string|null}> */
    private function delivery(Client $client): array
    {
        $email = collect($this->emailDelivery)->contains(static fn (Closure $check): bool => $check($client));

        return [
            'email' => [
                'enabled' => $email,
                'reason' => $email ? null : 'No email provider is configured yet, so nothing is sent by email. Your choices are saved and will be used as soon as one is.',
            ],
            'in_app' => [
                'enabled' => false,
                'reason' => 'In-app notifications are not delivered yet — there is no notification feed in this portal. Your choices are saved and will be used as soon as there is.',
            ],
        ];
    }

    private function dndActive(ClientPreference $prefs): bool
    {
        if ($prefs->dnd_ends_at === null) {
            return false;
        }

        return ($prefs->dnd_starts_at === null || $prefs->dnd_starts_at->lte(now())) && now()->lt($prefs->dnd_ends_at);
    }

    private function date(mixed $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(is_scalar($value) ? (string) $value : '');
        } catch (Throwable) {
            throw ApiException::badRequest('That Do Not Disturb time is not a valid date.');
        }
    }
}
