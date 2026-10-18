<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $country_id
 * @property string $name
 * @property bool $is_active
 */
final class State extends Model
{
    use SoftDeletes;

    protected $fillable = ['country_id', 'name', 'code', 'slug', 'sort_order', 'is_default', 'is_active', 'approval_status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<District, $this> */
    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }
}
