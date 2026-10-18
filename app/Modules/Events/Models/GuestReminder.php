<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A follow-up the host set on a guest. Only what a person sets is stored
 * (pending / done / dismissed); upcoming vs overdue is derived when read.
 *
 * @property int $id
 * @property int|null $guest_id
 * @property int|null $note_id
 * @property string $title
 * @property Carbon $due_at
 * @property string $status
 * @property Carbon|null $completed_at
 */
final class GuestReminder extends Model
{
    use SoftDeletes;

    protected $fillable = ['guest_id', 'participant_id', 'note_id', 'title', 'due_at', 'status', 'completed_at', 'created_by_client_id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function state(): string
    {
        return match ($this->status) {
            'done', 'dismissed' => $this->status,
            default => $this->due_at->isPast() ? 'overdue' : 'upcoming',
        };
    }
}
