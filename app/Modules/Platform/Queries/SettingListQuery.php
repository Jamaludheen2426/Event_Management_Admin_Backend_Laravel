<?php

declare(strict_types=1);

namespace App\Modules\Platform\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Platform\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** @extends ListQuery<Setting> */
final class SettingListQuery extends ListQuery
{
    protected array $searchable = ['key', 'description'];

    protected array $sortable = ['created_at', 'key', 'group'];

    protected function query(Request $request): Builder
    {
        return Setting::query();
    }

    protected function filter(Builder $query, Request $request): void
    {
        $group = $request->query('group');
        if (is_string($group) && $group !== '') {
            $query->where('group', $group);
        }
    }
}
