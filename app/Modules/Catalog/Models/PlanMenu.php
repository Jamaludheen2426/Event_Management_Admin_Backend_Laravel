<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One menu granted by one plan, with its display position (v1 subscription_plan_menus).
 *
 * @property int $id
 * @property int $plan_id
 * @property int $event_menu_id
 * @property int $sort_order
 */
final class PlanMenu extends Model
{
    protected $table = 'plan_menu';

    protected $fillable = ['plan_id', 'event_menu_id', 'sort_order'];

    /** @return BelongsTo<EventMenu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(EventMenu::class, 'event_menu_id');
    }
}
