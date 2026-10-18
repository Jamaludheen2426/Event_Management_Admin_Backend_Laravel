<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Identity\Models\PermissionGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** @extends ListQuery<PermissionGroup> */
final class ModuleListQuery extends ListQuery
{
    protected array $searchable = ['name', 'slug', 'description'];

    protected array $sortable = ['created_at', 'name'];

    protected function query(Request $request): Builder
    {
        return PermissionGroup::query()
            ->whereHas('permissions', static fn (Builder $q) => $q->where('guard_name', 'admin'))
            ->with(['permissions' => static fn ($q) => $q->where('guard_name', 'admin')]);
    }
}
