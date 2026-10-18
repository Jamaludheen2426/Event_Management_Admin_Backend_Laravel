<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A city / locality with its pincode. v1 called these "localities" and kept
 * them in `cities` with the district id in `city_id`.
 *
 * @property int $id
 * @property int $district_id
 * @property string $name
 * @property string|null $pincode
 * @property bool $is_active
 */
final class City extends Model
{
    use SoftDeletes;

    protected $fillable = ['district_id', 'name', 'pincode', 'is_default', 'is_active', 'approval_status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
