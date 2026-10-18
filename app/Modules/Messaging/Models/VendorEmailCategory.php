<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToVendor;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $vendor_id
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property int $is_active
 */
final class VendorEmailCategory extends Model
{
    use BelongsToVendor;
    use SoftDeletes;

    protected $fillable = ['vendor_id', 'name', 'description', 'sort_order', 'is_active'];

    protected $attributes = ['sort_order' => 0, 'is_active' => 1];
}
