<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A contact in a client's phone book. Never attached to an event by being
 * invited: someone who scans the QR becomes a participant, linked back here
 * by guest_id when their mobile matches.
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $group_id
 * @property string $name
 * @property string|null $mobile
 * @property string $source
 * @property-read GuestGroup|null $group
 */
final class Guest extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'group_id', 'source', ...Participant::PERSON];

    protected $attributes = ['source' => 'manual', 'dial_code' => '+91', 'country' => 'India'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<GuestGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(GuestGroup::class, 'group_id');
    }

    /** @return HasMany<Participant, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(Participant::class);
    }
}
