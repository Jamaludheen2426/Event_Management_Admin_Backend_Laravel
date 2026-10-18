<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use App\Modules\Catalog\Models\EventMenu;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One menu of one event: switched on by the host (selected) and/or placed in
 * the host's tile order (position). v1 menu_ids / menu_order.
 *
 * @property int $id
 * @property int $event_id
 * @property int $event_menu_id
 * @property bool $selected
 * @property int|null $position
 */
final class EventMenuChoice extends Model
{
    public $timestamps = false;

    protected $table = 'event_menu';

    protected $fillable = ['event_id', 'event_menu_id', 'selected', 'position'];

    protected function casts(): array
    {
        return ['selected' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<EventMenu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(EventMenu::class, 'event_menu_id')->withoutGlobalScopes();
    }
}
