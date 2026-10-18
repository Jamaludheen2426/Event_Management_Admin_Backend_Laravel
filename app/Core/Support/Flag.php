<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * v1's toBit(): what the frontends send for a switch — true/false, 1/0,
 * "true"/"1"/"yes" — as 1 or 0. Blank uses the fallback.
 */
final class Flag
{
    public static function bit(mixed $value, int $fallback = 0): int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['true', '1', 'yes'], true) ? 1 : 0;
    }

    /** v1 clampInt(): an integer within [min, max], fallback when blank or not a number. */
    public static function clamp(mixed $value, int $min, int $max, int $fallback): int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $fallback;
        }

        return min(max((int) $value, $min), $max);
    }

    /**
     * v1 oneOf(): the lower-cased value when allowed, else the fallback.
     *
     * @param  list<string>  $allowed
     */
    public static function oneOf(mixed $value, array $allowed, ?string $fallback): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return $fallback;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /** A positive integer id, or null (v1 parseInt / toNullableId). */
    public static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
