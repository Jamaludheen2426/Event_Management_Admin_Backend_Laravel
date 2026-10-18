<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\Concerns\HasAuthors;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A feature section of an event (Guests, Gallery, Agenda...). Plans grant
 * menus; is_default = 1 menus are granted by every plan and locked on.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $slug
 * @property int $is_default
 * @property int $is_active
 * @property int|null $event_category_id
 */
final class EventMenu extends Model
{
    use BelongsToCompany;
    use HasAuthors;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'slug', 'remarks', 'description', 'menu_group', 'event_category_id',
        'is_default', 'icon', 'color', 'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    /** @return BelongsTo<EventCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }
}
