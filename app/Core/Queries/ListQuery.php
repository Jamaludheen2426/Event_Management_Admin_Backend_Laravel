<?php

declare(strict_types=1);

namespace App\Core\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Base for every admin/portal list endpoint. Reads the same query string the
 * Node API read, with the same rules:
 *
 *   ?search=     LIKE %term% across $searchable, OR-ed
 *   ?is_active=  'true' / 'false' / 0 / 1 / 2   (only when $filtersActive)
 *   ?sort_by=    one of $sortable; an unknown column falls back to created_at
 *   ?sort_order= 'asc' (any case) or descending
 *   ?page=       from 1                ?limit=  1..1000, default 10
 *
 * A subclass supplies the base query (already tenant-scoped by its model) and
 * any module-specific filters.
 *
 * @template TModel of Model
 */
abstract class ListQuery
{
    /** @var list<string> */
    protected array $searchable = [];

    /** @var list<string> */
    protected array $sortable = ['created_at'];

    /**
     * v1 sort keys whose column was renamed: v1 key => column.
     *
     * @var array<string, string>
     */
    protected array $sortAliases = [];

    /** Used when the request names no sort column. */
    protected string $defaultSort = 'created_at';

    /** Used when the request names no direction. */
    protected string $defaultDirection = 'desc';

    protected bool $filtersActive = true;

    /** The table has an approval_status column (rows created under approval). */
    protected bool $approvable = false;

    protected int $defaultLimit = 10;

    protected int $maxLimit = 1000;

    /** @return Builder<TModel> */
    abstract protected function query(Request $request): Builder;

    /** @param Builder<TModel> $query */
    protected function filter(Builder $query, Request $request): void {}

    /** Order used when the request names none (v1 lists differ: sort_order ASC, created_at DESC...). */
    public function defaultOrder(string $column, string $direction = 'asc'): static
    {
        $this->defaultSort = $column;
        $this->defaultDirection = $direction;

        return $this;
    }

    /** @return LengthAwarePaginator<int, TModel> */
    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = $this->query($request);

        $this->applySearch($query, $request);
        if ($this->filtersActive) {
            $this->applyActive($query, $request);
        }
        $this->filter($query, $request);

        [$column, $direction] = $this->sort($request);
        $query->orderBy($query->qualifyColumn($column), $direction);

        $page = max(1, (int) $request->query('page', '1'));

        return $query->paginate($this->limit($request), ['*'], 'page', $page);
    }

    /** @param Builder<TModel> $query */
    private function applySearch(Builder $query, Request $request): void
    {
        $term = trim((string) $request->query('search', ''));
        if ($term === '' || $this->searchable === []) {
            return;
        }

        $query->where(function (Builder $where) use ($term): void {
            foreach ($this->searchable as $column) {
                $where->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /** @param Builder<TModel> $query */
    private function applyActive(Builder $query, Request $request): void
    {
        $value = $request->query('is_active');
        if ($value === null || $value === '') {
            return;
        }

        $flag = match ((string) $value) {
            'true' => 1,
            'false' => 0,
            default => (int) $value,
        };

        if (! $this->approvable) {
            $query->where($query->qualifyColumn('is_active'), $flag);

            return;
        }

        // v1 stored "pending approval" as is_active = 2; it is now its own column.
        if ($flag === 2) {
            $query->where($query->qualifyColumn('approval_status'), 'pending');

            return;
        }
        $query->where($query->qualifyColumn('is_active'), $flag)
            ->where($query->qualifyColumn('approval_status'), 'approved');
    }

    /** @return array{0: string, 1: string} */
    private function sort(Request $request): array
    {
        $requested = $request->query('sort_by');
        if (is_string($requested) && isset($this->sortAliases[$requested])) {
            $requested = $this->sortAliases[$requested];
        }
        $column = match (true) {
            $requested === null || $requested === '' => $this->defaultSort,
            in_array($requested, $this->sortable, true) => (string) $requested,
            default => 'created_at',
        };

        $order = $request->query('sort_order');
        $direction = $order === null || $order === ''
            ? $this->defaultDirection
            : (strtolower((string) $order) === 'asc' ? 'asc' : 'desc');

        return [$column, $direction];
    }

    private function limit(Request $request): int
    {
        $limit = (int) $request->query('limit', (string) $this->defaultLimit);

        return min($this->maxLimit, max(1, $limit === 0 ? $this->defaultLimit : $limit));
    }
}
