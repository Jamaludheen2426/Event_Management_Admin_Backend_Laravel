<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToVendor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor's own e-mail (newsletters); `description` holds its content.
 *
 * @property int $id
 * @property int $vendor_id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $description
 * @property int $is_active
 * @property-read VendorEmailCategory|null $category
 */
final class VendorEmailTemplate extends Model
{
    use BelongsToVendor;
    use SoftDeletes;

    protected $fillable = ['vendor_id', 'category_id', 'name', 'description', 'is_active'];

    protected $attributes = ['is_active' => 1];

    /** @return BelongsTo<VendorEmailCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(VendorEmailCategory::class, 'category_id');
    }
}
