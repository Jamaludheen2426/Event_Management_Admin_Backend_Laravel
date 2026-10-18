<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Identity\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * v1 sorted departments by name, A to Z.
 *
 * @extends ListQuery<Department>
 */
final class DepartmentListQuery extends ListQuery
{
    protected array $searchable = ['name', 'description'];

    protected array $sortable = ['name', 'created_at'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected function query(Request $request): Builder
    {
        return Department::query();
    }
}
