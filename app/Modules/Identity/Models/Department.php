<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An admin-side department (company layer). Vendor departments are separate
 * (vendor layer, Phase 4).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property bool $is_active
 */
final class Department extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
