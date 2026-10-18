<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A district. v1 called these "cities" in its API (`/locations/cities`,
 * users.city_id); the v1 resources keep those names.
 *
 * @property int $id
 * @property int $state_id
 * @property string $name
 * @property bool $is_active
 */
final class District extends Model
{
    use SoftDeletes;

    protected $fillable = ['state_id', 'name', 'slug', 'sort_order', 'is_default', 'is_active', 'approval_status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return HasMany<City, $this> */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
