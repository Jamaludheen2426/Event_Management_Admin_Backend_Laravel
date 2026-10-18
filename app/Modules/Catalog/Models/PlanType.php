<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor subscription tier (Basic, Gold...). is_custom = 1 tiers are made
 * for one vendor (vendor_id).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int|null $vendor_id
 */
final class PlanType extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'description', 'price', 'discounted_price', 'validity', 'features', 'is_custom',
        'vendor_id', 'label_color', 'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'discounted_price' => 'decimal:2'];
    }

    /** @return BelongsToMany<Menu, $this> */
    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'plan_type_menu')->withPivot('sort_order')->orderByPivot('sort_order');
    }
}
