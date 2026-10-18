<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a participant's RSVP answer — including an answer the host
 * took back, which otherwise leaves no trace on the participant row.
 *
 * @property int $id
 * @property int $participant_id
 * @property string|null $from_response_type
 * @property string $to_response_type
 * @property int $party_size
 * @property string|null $dietary_preference
 * @property string $accommodation
 * @property string|null $notes
 * @property string $source
 * @property Carbon $changed_at
 * @property-read Participant $participant
 */
final class ParticipantResponseLog extends Model
{
    protected $fillable = [
        'participant_id', 'from_response_type', 'to_response_type', 'party_size', 'dietary_preference', 'accommodation',
        'notes', 'source', 'changed_by_client_id', 'changed_at',
    ];

    protected function casts(): array
    {
        return ['party_size' => 'integer', 'changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class)->withTrashed();
    }
}
