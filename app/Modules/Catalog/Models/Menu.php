<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor-portal menu that a vendor plan type unlocks (v1 table "menus").
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 */
final class Menu extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'icon', 'icon_fill_color_light', 'icon_fill_color_dark', 'display_status',
        'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];
}
