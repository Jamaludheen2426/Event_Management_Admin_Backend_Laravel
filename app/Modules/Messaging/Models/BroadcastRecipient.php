<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Modules\Events\Models\Participant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One participant's copy of a broadcast, and what happened to it.
 *
 * @property int $id
 * @property int|null $broadcast_id
 * @property int $event_id
 * @property int|null $participant_id
 * @property int|null $client_id
 * @property string $channel
 * @property string|null $kind
 * @property string $status
 * @property string $sender
 * @property int|null $sender_client_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $clicked_at
 * @property string|null $failed_reason
 * @property Carbon|null $created_at
 * @property-read Participant|null $participant
 */
final class BroadcastRecipient extends Model
{
    protected $table = 'event_broadcast_recipients';

    protected $fillable = [
        'broadcast_id', 'event_id', 'participant_id', 'client_id', 'channel', 'kind', 'status', 'sender',
        'sender_client_id', 'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'is_read', 'read_at', 'failed_reason',
    ];

    protected $attributes = ['status' => 'queued', 'sender' => 'system', 'is_read' => false];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'opened_at' => 'datetime',
            'clicked_at' => 'datetime', 'read_at' => 'datetime', 'is_read' => 'boolean',
        ];
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class)->withTrashed();
    }
}
