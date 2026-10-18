<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * A family member shown on the event (the couple's families).
 *
 * @property int $id
 * @property int $event_id
 * @property string|null $title
 * @property string $name
 * @property string|null $photo
 * @property Carbon|null $date_of_birth
 * @property string $side
 * @property string|null $relationship
 * @property int|null $relationship_option_id
 * @property string|null $dial_code
 * @property string|null $mobile
 * @property string|null $email
 * @property string|null $notes
 * @property int $sort_order
 * @property Carbon|null $created_at
 */
final class FamilyMember extends Model
{
    protected $fillable = ['event_id', 'title', 'name', 'photo', 'date_of_birth', 'side', 'relationship', 'relationship_option_id', 'dial_code', 'mobile', 'email', 'notes', 'sort_order'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'sort_order' => 'integer'];
    }
}
