<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A label on a guest ("VIP", "Out of town").
 *
 * @property int $id
 * @property int|null $guest_id
 * @property string $label
 * @property string|null $color
 */
final class GuestTag extends Model
{
    use SoftDeletes;

    protected $fillable = ['guest_id', 'participant_id', 'label', 'color'];
}
