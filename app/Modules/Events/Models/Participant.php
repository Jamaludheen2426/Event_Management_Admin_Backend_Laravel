<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Someone attending one event: joined by scanning its QR, or added by the
 * host. Carries the person (as a phone-book guest does) and their answers
 * about this event (RSVP, party size, table).
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $guest_id
 * @property int|null $client_id
 * @property string $name
 * @property string|null $mobile
 * @property string|null $photo
 * @property int $party_size
 * @property string $rsvp_status
 * @property string $response_type
 * @property string $invite_source
 * @property Carbon|null $responded_at
 * @property-read Event $event
 * @property-read Guest|null $guest
 */
final class Participant extends Model
{
    use SoftDeletes;

    public const PERSON = [
        'title', 'first_name', 'last_name', 'name', 'date_of_birth', 'gender', 'email', 'dial_code', 'mobile', 'whatsapp',
        'company', 'relationship', 'relationship_option_id', 'address_line1', 'address_line2', 'city', 'state',
        'postal_code', 'country', 'dietary_preference', 'food_preference_option_id', 'special_requirements', 'notes', 'photo',
    ];

    public const ATTENDANCE = [
        'table_number', 'party_size', 'rsvp_status', 'response_type', 'invite_source', 'invited_at', 'responded_at',
        'plus_one', 'plus_one_count', 'custom_answers', 'accommodation', 'rsvp_side',
    ];

    protected $fillable = ['event_id', 'guest_id', 'client_id', 'added_by_client_id', ...self::PERSON, ...self::ATTENDANCE];

    protected $attributes = [
        'party_size' => 1, 'rsvp_status' => 'not_responded', 'response_type' => 'none', 'invite_source' => 'manual',
        'accommodation' => 'unknown', 'plus_one' => false, 'plus_one_count' => 0, 'dial_code' => '+91', 'country' => 'India',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'party_size' => 'integer',
            'plus_one' => 'boolean',
            'plus_one_count' => 'integer',
            'custom_answers' => 'array',
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
