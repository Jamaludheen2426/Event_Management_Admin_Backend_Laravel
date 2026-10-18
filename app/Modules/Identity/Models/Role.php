<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Database\SerializesV1Dates;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role. `name` holds the v1 slug ("super_admin"), `label` the display name.
 *
 * team_id is the company for admin roles (guard admin) and the vendor for
 * vendor roles (guard vendor). `level` orders admin roles:
 * developer 1000 > super_admin 100 > admin 50 > subadmin 25 > custom 10.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string $name
 * @property string|null $label
 * @property int $level
 * @property bool $is_default
 * @property bool $is_active
 * @property string $approval_status
 */
final class Role extends SpatieRole
{
    use SerializesV1Dates;

    /** Roles that pass every permission check of their portal. */
    public const BYPASS = ['developer', 'super_admin'];

    public const LEVEL_DEVELOPER = 1000;

    public const LEVEL_SUPER_ADMIN = 100;

    protected $fillable = [
        'team_id', 'name', 'guard_name', 'label', 'description', 'level',
        'is_default', 'is_active', 'approval_status', 'approved_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Permissions, with the per-role approval flag.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        // config/permission.php points spatie at our Permission model.
        /** @var BelongsToMany<Permission, $this> $relation */
        $relation = $this->belongsToMany(
            Permission::class,
            (string) config('permission.table_names.role_has_permissions'),
            'role_id',
            'permission_id',
        );

        return $relation->withPivot('requires_approval');
    }

    public function bypassesPermissions(): bool
    {
        return in_array($this->name, self::BYPASS, true);
    }
}
