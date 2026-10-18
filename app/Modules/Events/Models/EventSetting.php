<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;

/**
 * An event's per-feature settings (v1 JSON columns on the event row).
 *
 * @property int $id
 * @property int $event_id
 * @property array<string, mixed>|null $rsvp
 * @property array<string, mixed>|null $participant
 * @property array<string, mixed>|null $family
 * @property array<string, mixed>|null $wish
 * @property array<string, mixed>|null $wish_filter
 */
final class EventSetting extends Model
{
    protected $fillable = ['event_id', 'rsvp', 'participant', 'family', 'wish', 'wish_filter'];

    protected function casts(): array
    {
        return ['rsvp' => 'array', 'participant' => 'array', 'family' => 'array', 'wish' => 'array', 'wish_filter' => 'array'];
    }
}
