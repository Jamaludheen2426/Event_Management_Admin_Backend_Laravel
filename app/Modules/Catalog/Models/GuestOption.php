<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One entry of a guest registration dropdown: kind relationship or
 * food_preference. event_category_id NULL = the fallback list.
 *
 * @property int $id
 * @property int $company_id
 * @property string $kind
 * @property int|null $event_category_id
 * @property string $name
 */
final class GuestOption extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    public const RELATIONSHIP = 'relationship';

    public const FOOD_PREFERENCE = 'food_preference';

    protected $fillable = [
        'company_id', 'kind', 'event_category_id', 'name', 'description', 'icon', 'color', 'sort_order',
        'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    /** @return BelongsTo<EventCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }
}
