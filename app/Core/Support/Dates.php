<?php

declare(strict_types=1);

namespace App\Core\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Dates the way v1 sent them: instants as ISO UTC, calendar dates as Y-m-d. */
final class Dates
{
    public static function at(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function day(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }
}
