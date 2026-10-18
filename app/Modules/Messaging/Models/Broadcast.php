<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Something an organizer sends to their event's participants: a message from
 * the Messages screen (source "message") or a notification from the app
 * (source "notice").
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $sent_by_client_id
 * @property string $source
 * @property string $title
 * @property string|null $body
 * @property string|null $channel
 * @property list<string>|null $channels
 * @property string|null $kind
 * @property string $audience
 * @property list<int>|null $group_ids
 * @property list<int>|null $participant_ids
 * @property list<string>|null $sides
 * @property list<string>|null $relationships
 * @property string|null $image_url
 * @property string|null $click_action
 * @property string|null $deep_link
 * @property array<string, string>|null $data_payload
 * @property array<string, mixed>|null $push_options
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property string|null $timezone
 * @property Carbon|null $sent_at
 * @property int $recipients_count
 * @property int $delivered_count
 * @property int $failed_count
 * @property string|null $failed_reason
 * @property Carbon|null $created_at
 * @property-read Event|null $event
 */
final class Broadcast extends Model
{
    use SoftDeletes;

    public const MESSAGE = 'message';

    public const NOTICE = 'notice';

    protected $table = 'event_broadcasts';

    protected $fillable = [
        'event_id', 'sent_by_client_id', 'source', 'title', 'body', 'channel', 'channels', 'kind', 'audience',
        'group_ids', 'participant_ids', 'sides', 'relationships', 'image_url', 'click_action', 'deep_link',
        'data_payload', 'push_options', 'status', 'scheduled_at', 'timezone', 'sent_at', 'recipients_count',
        'delivered_count', 'failed_count', 'failed_reason',
    ];

    protected $attributes = ['audience' => 'all', 'status' => 'draft', 'recipients_count' => 0, 'delivered_count' => 0, 'failed_count' => 0];

    protected function casts(): array
    {
        return [
            'channels' => 'array', 'group_ids' => 'array', 'participant_ids' => 'array', 'sides' => 'array',
            'relationships' => 'array', 'data_payload' => 'array', 'push_options' => 'array',
            'scheduled_at' => 'datetime', 'sent_at' => 'datetime',
            'recipients_count' => 'integer', 'delivered_count' => 'integer', 'failed_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withoutGlobalScopes();
    }

    /** @return HasMany<BroadcastRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
