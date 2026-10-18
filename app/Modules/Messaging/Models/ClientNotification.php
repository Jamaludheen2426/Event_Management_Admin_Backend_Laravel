<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Participant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of a client's notification feed ("New RSVP", "A wish was flagged").
 *
 * @property int $id
 * @property int $client_id
 * @property string $category
 * @property string $type
 * @property string $title
 * @property string|null $body
 * @property int|null $event_id
 * @property int|null $participant_id
 * @property string|null $link
 * @property array<string, mixed>|null $meta
 * @property bool $is_read
 * @property Carbon|null $read_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property-read Event|null $event
 * @property-read Participant|null $participant
 */
final class ClientNotification extends Model
{
    protected $fillable = [
        'client_id', 'category', 'type', 'title', 'body', 'event_id', 'participant_id', 'link', 'meta',
        'is_read', 'read_at', 'archived_at',
    ];

    protected $attributes = ['is_read' => false];

    protected function casts(): array
    {
        return ['meta' => 'array', 'is_read' => 'boolean', 'read_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class)->withTrashed();
    }
}
