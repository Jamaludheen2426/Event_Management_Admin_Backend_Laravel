<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of permissions — the v1 "module" (employees, roles, settings, ...).
 * Global: the catalogue is the same for every company and vendor.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 */
final class PermissionGroup extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Permission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'permission_group_id');
    }
}
