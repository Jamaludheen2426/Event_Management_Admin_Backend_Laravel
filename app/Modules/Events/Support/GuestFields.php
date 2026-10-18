<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Phone;
use App\Modules\Catalog\Models\GuestOption;
use App\Modules\Events\Models\GuestGroup;

/**
 * The person fields a phone-book guest and a participant share, and the
 * attendance answers only a participant has (v1 guestFields.js). Messages are
 * v1's.
 */
final class GuestFields
{
    public const RSVP_STATUSES = ['not_responded', 'invited', 'pending', 'accepted', 'declined'];

    public const RESPONSE_TYPES = ['none', 'yes', 'no', 'maybe'];

    private const INVITE_SOURCES = ['whatsapp', 'email', 'sms', 'manual', 'import', 'qr'];

    private const OPTIONAL = [
        'company' => 200, 'address_line1' => 255, 'address_line2' => 255, 'city' => 120, 'state' => 120, 'postal_code' => 20,
        'country' => 100, 'dietary_preference' => 255, 'special_requirements' => 500, 'notes' => 500, 'relationship' => 60,
        'photo' => 500,
    ];

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $existing  the row being edited (for the composed name)
     * @return array<string, mixed>
     */
    public static function person(int $clientId, array $input, bool $partial, ?array $existing = null, bool $withGroup = true): array
    {
        $has = static fn (string $f): bool => array_key_exists($f, $input);
        $required = static fn (string $f): bool => ! $partial || array_key_exists($f, $input);
        $v = [];

        if ($withGroup && $has('group_id')) {
            $raw = $input['group_id'];
            if ($raw === '' || $raw === null) {
                $v['group_id'] = null;
            } else {
                $v['group_id'] = GuestGroup::query()->where('client_id', $clientId)->whereKey((int) $raw)->value('id')
                    ?? throw ApiException::badRequest('That guest group is not on your account.');
            }
        }
        if ($required('first_name')) {
            $v['first_name'] = self::str($input['first_name'] ?? null, 100) ?? throw ApiException::badRequest('Please enter the first name.');
        }
        if ($has('last_name')) {
            $v['last_name'] = self::str($input['last_name'], 100);
        }
        if ($has('title')) {
            $v['title'] = self::str($input['title'], 20);
        }
        if ($has('date_of_birth')) {
            $raw = self::str($input['date_of_birth'], 10);
            if ($raw !== null) {
                $parsed = \DateTime::createFromFormat('!Y-m-d', $raw);
                if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
                    throw ApiException::badRequest('Please enter a valid date of birth.');
                }
                if ($raw > today()->format('Y-m-d')) {
                    throw ApiException::badRequest('Date of birth cannot be in the future.');
                }
            }
            $v['date_of_birth'] = $raw;
        }
        if (array_key_exists('first_name', $v) || array_key_exists('last_name', $v)) {
            $first = $v['first_name'] ?? ($existing['first_name'] ?? null);
            $last = array_key_exists('last_name', $v) ? $v['last_name'] : ($existing['last_name'] ?? null);
            $v['name'] = self::composeName($first, $last, $existing['name'] ?? null);
        }
        if ($has('email')) {
            $email = self::str($input['email'], 150);
            if ($email !== null && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email) !== 1) {
                throw ApiException::badRequest('Please enter a valid email address.');
            }
            $v['email'] = $email !== null ? mb_strtolower($email) : null;
        }
        if ($has('dial_code')) {
            $v['dial_code'] = self::str($input['dial_code'], 8);
        }
        foreach (['mobile', 'whatsapp'] as $field) {
            if (! $has($field) && ! ($field === 'mobile' && $required('mobile'))) {
                continue;
            }
            $value = self::str($input[$field] ?? null, 20);
            if ($field === 'mobile' && $value === null) {
                throw ApiException::badRequest('Please enter the mobile number.');
            }
            if ($value !== null && preg_match('/^[+\d][\d\s-]{4,}$/', $value) !== 1) {
                throw ApiException::badRequest('Please enter a valid phone number.');
            }
            $v[$field] = $value;
        }
        foreach (self::OPTIONAL as $field => $max) {
            if ($has($field)) {
                $v[$field] = self::str($input[$field], $max);
            }
        }
        if ($has('gender')) {
            $v['gender'] = in_array($input['gender'], ['male', 'female', 'other'], true) ? $input['gender'] : null;
        }
        foreach (['relationship_option_id', 'food_preference_option_id'] as $field) {
            if ($has($field)) {
                $id = is_numeric($input[$field]) ? (int) $input[$field] : 0;
                $v[$field] = $id > 0 && GuestOption::query()->withoutGlobalScopes()->whereKey($id)->exists() ? $id : null;
            }
        }

        return $v;
    }

    /**
     * RSVP, party size, table... A response sets the matching status and the
     * time of answering, unless a status was given too.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public static function attendance(array $input, ?array $existing = null): array
    {
        $has = static fn (string $f): bool => array_key_exists($f, $input);
        $v = [];
        if ($has('table_number')) {
            $v['table_number'] = self::str($input['table_number'], 30);
        }
        if ($has('party_size')) {
            $size = filter_var($input['party_size'], FILTER_VALIDATE_INT);
            if ($size === false || $size < 1 || $size > 50) {
                throw ApiException::badRequest('Party size must be between 1 and 50.');
            }
            $v['party_size'] = $size;
        }
        if ($has('rsvp_status')) {
            $v['rsvp_status'] = self::oneOf($input['rsvp_status'], self::RSVP_STATUSES, 'Invalid RSVP status.');
        }
        if ($has('response_type')) {
            $v['response_type'] = self::oneOf($input['response_type'] ?? 'none', self::RESPONSE_TYPES, 'Invalid response type.');
        }
        if ($has('invite_source')) {
            $v['invite_source'] = self::oneOf($input['invite_source'] ?? 'manual', self::INVITE_SOURCES, 'Invalid invite source.');
        }

        $response = $v['response_type'] ?? ($existing['response_type'] ?? null);
        if (! array_key_exists('rsvp_status', $v) && $response !== null && $response !== 'none') {
            $v['rsvp_status'] = match ($response) {
                'yes' => 'accepted',
                'no' => 'declined',
                default => 'pending',
            };
        }
        if (isset($v['response_type']) && $v['response_type'] !== 'none' && ($existing['responded_at'] ?? null) === null) {
            $v['responded_at'] = now();
        }
        if (($v['response_type'] ?? null) === 'none') {
            $v['responded_at'] = null;
        }

        if ($has('plus_one')) {
            $v['plus_one'] = (bool) $input['plus_one'];
        }
        if ($has('plus_one_count')) {
            $count = (int) $input['plus_one_count'];
            if ($count < 0 || $count > 20) {
                throw ApiException::badRequest('Plus one count must be 20 or fewer.');
            }
            $v['plus_one_count'] = $count;
        }
        if (($v['plus_one'] ?? null) === false) {
            $v['plus_one_count'] = 0;
        }
        if ($has('custom_answers')) {
            $v['custom_answers'] = is_array($input['custom_answers']) ? $input['custom_answers'] : null;
        }
        if ($has('rsvp_side') || $has('side')) {
            $raw = $input['rsvp_side'] ?? $input['side'] ?? null;
            $side = is_string($raw) ? mb_strtolower(trim($raw)) : null;
            if ($side === null || $side === '' || in_array($side, ['groom', 'bride'], true)) {
                $v['rsvp_side'] = $side === '' ? null : $side;
            }
        }

        return $v;
    }

    public static function composeName(?string $first, ?string $last, ?string $fallback): string
    {
        $joined = trim(implode(' ', array_filter([$first, $last], static fn ($p): bool => $p !== null && $p !== '')));

        return $joined !== '' ? $joined : ($fallback ?? '');
    }

    /** The last ten digits — how two spellings of one number are compared. */
    public static function mobileKey(?string $mobile): string
    {
        return substr(Phone::digits($mobile), -10);
    }

    public static function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $message): string
    {
        $value = mb_strtolower(is_scalar($value) ? (string) $value : '');

        return in_array($value, $allowed, true) ? $value : throw ApiException::badRequest($message);
    }
}
