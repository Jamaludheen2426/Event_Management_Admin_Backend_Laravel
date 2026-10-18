<?php

declare(strict_types=1);

namespace App\Modules\Platform\Queries;

use App\Core\Queries\ListQuery;
use App\Modules\Platform\Models\TranslationKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ?group= filters by group.
 *
 * @extends ListQuery<TranslationKey>
 */
final class TranslationKeyListQuery extends ListQuery
{
    protected array $searchable = ['key', 'default_value', 'description'];

    protected array $sortable = ['created_at', 'key', 'group'];

    protected bool $filtersActive = false;

    protected function query(Request $request): Builder
    {
        return TranslationKey::query()->with('translations.language');
    }

    protected function filter(Builder $query, Request $request): void
    {
        $group = $request->query('group');
        if (is_string($group) && $group !== '') {
            $query->where('group', $group);
        }
    }
}
