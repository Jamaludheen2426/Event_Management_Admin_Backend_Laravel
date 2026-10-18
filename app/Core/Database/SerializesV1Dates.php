<?php

declare(strict_types=1);

namespace App\Core\Database;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Dates serialize the way the Node API sent them: "2026-10-17T10:00:00.000Z".
 * Used by the base Model and by authenticatable models, which cannot extend it.
 */
trait SerializesV1Dates
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
