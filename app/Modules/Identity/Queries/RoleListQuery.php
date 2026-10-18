<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Admin roles of the current company. Roles are not company-scoped by a global
 * scope (spatie owns the model), so the team filter is explicit here.
 *
 * v1 `name` (display name) is the `label` column now; v1 `slug` is `name`.
 *
 * @extends ListQuery<Role>
 */
final class RoleListQuery extends ListQuery
{
    protected array $searchable = ['label', 'name', 'description'];

    protected array $sortable = ['created_at', 'label', 'level'];

    protected array $sortAliases = ['name' => 'label'];

    protected bool $approvable = true;

    protected function query(Request $request): Builder
    {
        return Role::query()->where('guard_name', 'admin')->where('team_id', getPermissionsTeamId());
    }
}
