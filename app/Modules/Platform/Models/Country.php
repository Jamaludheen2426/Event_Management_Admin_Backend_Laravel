<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $is_active
 */
final class Country extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'flag', 'nationality', 'sort_order', 'is_default', 'is_active', 'approval_status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return HasMany<State, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
