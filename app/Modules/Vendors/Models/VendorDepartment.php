<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToVendor;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A department inside one vendor (staff are placed in one).
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $name
 * @property bool $is_active
 */
final class VendorDepartment extends Model
{
    use BelongsToVendor;
    use SoftDeletes;

    protected $fillable = ['vendor_id', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
