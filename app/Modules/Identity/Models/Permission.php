<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Database\SerializesV1Dates;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * A permission. Global (no tenant). `name` holds the v1 slug
 * ("employees.view"), `label` the display text, the group is the v1 "module".
 *
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property int|null $permission_group_id
 * @property bool $is_active
 */
final class Permission extends SpatiePermission
{
    use SerializesV1Dates;

    protected $fillable = ['name', 'guard_name', 'label', 'permission_group_id', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<PermissionGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PermissionGroup::class, 'permission_group_id');
    }

    /** The v1 `module` value: the slug before the first dot. */
    public function moduleSlug(): string
    {
        return explode('.', $this->name, 2)[0];
    }
}
