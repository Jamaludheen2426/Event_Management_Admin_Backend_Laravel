<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A language (global reference data).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $direction
 * @property bool $is_default
 * @property bool $is_active
 * @property string $approval_status
 */
final class Language extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'native_name', 'direction', 'is_default', 'is_active', 'approval_status', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return HasMany<Translation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }
}
