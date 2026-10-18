<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What the HOST wrote about a guest (not the guest's own RSVP note).
 *
 * @property int $id
 * @property int|null $guest_id
 * @property string $title
 * @property string|null $body
 * @property string $category
 * @property string $visibility
 * @property bool $is_pinned
 * @property int|null $created_by_client_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class GuestNote extends Model
{
    use SoftDeletes;

    protected $fillable = ['guest_id', 'participant_id', 'title', 'body', 'category', 'visibility', 'is_pinned', 'created_by_client_id'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }
}
