<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * One slot of an event's schedule.
 *
 * @property int $id
 * @property int $event_id
 * @property string $title
 * @property string|null $description
 * @property Carbon $agenda_date
 * @property Carbon|null $end_date
 * @property string $start_time
 * @property string $end_time
 * @property string|null $location
 * @property list<string>|null $images
 * @property int $sort_order
 */
final class AgendaItem extends Model
{
    protected $fillable = ['event_id', 'title', 'description', 'agenda_date', 'end_date', 'start_time', 'end_time', 'location', 'images', 'sort_order'];

    protected function casts(): array
    {
        return ['agenda_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'images' => 'array', 'sort_order' => 'integer'];
    }
}
