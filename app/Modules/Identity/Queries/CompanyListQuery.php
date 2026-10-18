<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Platform\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** @extends ListQuery<Company> */
final class CompanyListQuery extends ListQuery
{
    protected array $searchable = ['name', 'slug', 'email', 'phone'];

    protected array $sortable = ['created_at', 'name', 'is_active'];

    protected function query(Request $request): Builder
    {
        return Company::query();
    }
}
