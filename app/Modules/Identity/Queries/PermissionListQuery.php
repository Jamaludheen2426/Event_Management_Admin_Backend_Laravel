<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Identity\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Admin permissions. v1 `name` = label column, v1 `slug` = name column,
 * v1 `module` = the group. ?module=<slug> filters by group.
 *
 * @extends ListQuery<Permission>
 */
final class PermissionListQuery extends ListQuery
{
    protected array $searchable = ['label', 'name', 'description'];

    protected array $sortable = ['created_at', 'label', 'name'];

    protected array $sortAliases = ['name' => 'label', 'slug' => 'name', 'module' => 'name'];

    protected function query(Request $request): Builder
    {
        return Permission::query()->where('guard_name', 'admin')->with('group');
    }

    protected function filter(Builder $query, Request $request): void
    {
        $module = $request->query('module');
        if (is_string($module) && $module !== '') {
            $query->whereHas('group', static fn (Builder $group) => $group->where('slug', $module));
        }
    }
}
