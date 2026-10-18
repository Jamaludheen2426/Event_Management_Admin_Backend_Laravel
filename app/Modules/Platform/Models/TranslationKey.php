<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-panel UI string ("common.save"). Global.
 *
 * @property int $id
 * @property string $key
 * @property string|null $default_value
 * @property string $group
 */
final class TranslationKey extends Model
{
    protected $fillable = ['key', 'default_value', 'description', 'group', 'approval_status', 'created_by', 'updated_by'];

    /** @return HasMany<Translation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }
}
