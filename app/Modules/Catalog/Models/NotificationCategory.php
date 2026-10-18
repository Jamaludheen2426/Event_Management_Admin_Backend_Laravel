<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 */
final class NotificationCategory extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'description', 'icon', 'color', 'sort_order', 'is_active',
        'approval_status', 'created_by', 'updated_by',
    ];
}
