<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\Concerns\HasAuthors;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wedding, birthday... Decides which menus, plans, templates and guest options apply.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int $is_active
 */
final class EventCategory extends Model
{
    use BelongsToCompany;
    use HasAuthors;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'description', 'icon', 'color', 'sort_order', 'is_active',
        'approval_status', 'created_by', 'updated_by',
    ];
}
