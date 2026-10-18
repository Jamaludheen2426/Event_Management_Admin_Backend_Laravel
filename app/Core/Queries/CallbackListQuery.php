<?php

declare(strict_types=1);

namespace App\Core\Queries;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * A ListQuery assembled from parts, for lists that need no class of their own
 * (reference tables): a base query, the searchable and sortable columns, and
 * an optional filter.
 *
 * @template TModel of Model
 *
 * @extends ListQuery<TModel>
 */
final class CallbackListQuery extends ListQuery
{
    /**
     * @param  Builder<TModel>  $base
     * @param  list<string>  $searchable
     * @param  list<string>  $sortable
     * @param  (Closure(Builder<TModel>, Request): void)|null  $extraFilter
     */
    public function __construct(
        private readonly Builder $base,
        array $searchable,
        array $sortable,
        bool $approvable = false,
        private readonly ?Closure $extraFilter = null,
    ) {
        $this->searchable = $searchable;
        $this->sortable = $sortable;
        $this->approvable = $approvable;
    }

    protected function query(Request $request): Builder
    {
        return $this->base;
    }

    protected function filter(Builder $query, Request $request): void
    {
        if ($this->extraFilter !== null) {
            ($this->extraFilter)($query, $request);
        }
    }
}
