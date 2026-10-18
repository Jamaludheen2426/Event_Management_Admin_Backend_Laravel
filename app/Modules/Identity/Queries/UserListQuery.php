<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** @extends ListQuery<User> */
final class UserListQuery extends ListQuery
{
    protected array $searchable = ['full_name', 'email', 'phone'];

    protected array $sortable = ['created_at', 'full_name', 'email'];

    protected bool $approvable = true;

    protected function query(Request $request): Builder
    {
        return User::query()->with(['roles', 'department']);
    }

    protected function filter(Builder $query, Request $request): void
    {
        $departmentId = $request->query('department_id');
        if (is_numeric($departmentId)) {
            $query->where('department_id', (int) $departmentId);
        }
    }
}
