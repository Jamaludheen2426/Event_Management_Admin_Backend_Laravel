<?php

declare(strict_types=1);

namespace App\Core\Approvals;

use App\Core\Http\Resources\V1Resource;
use App\Core\Tenancy\CurrentCompany;
use App\Core\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Which rows of a list have an open update or delete request.
 *
 * v1 lists carry `has_pending_approval` so the frontends can disable edit and
 * delete on those rows. Matched on module AND resource type (v1 matched the
 * module only, so a country and a state with the same id lit each other up).
 */
final class PendingApprovals
{
    /**
     * @param  list<int>  $ids
     * @return array<int, true> id => true for rows with an open request
     */
    public static function for(string $module, string $resourceType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $lookup = static fn () => ApprovalRequest::query()->pending()
            ->where('module_slug', $module)
            ->where('resource_type', $resourceType)
            ->whereIn('action', ['update', 'edit', 'delete'])
            ->whereIn('resource_id', $ids)
            ->pluck('resource_id');

        // Public lists (no login) of the global reference tables: no company to scope by.
        $found = app(CurrentCompany::class)->has() ? $lookup() : Tenancy::bypass($lookup);

        return array_fill_keys($found->map(static fn ($id): int => (int) $id)->all(), true);
    }

    /**
     * Wrap a page of models in their v1 resource, each flagged with
     * has_pending_approval. One query for the whole page.
     *
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @param  class-string<V1Resource>  $resource
     * @return LengthAwarePaginator<int, V1Resource>
     */
    public static function annotate(LengthAwarePaginator $page, string $resource, string $module, string $resourceType): LengthAwarePaginator
    {
        $ids = array_map(static fn (Model $model): int => (int) $model->getKey(), $page->items());
        $pending = self::for($module, $resourceType, $ids);

        return $page->through(static fn (Model $model): V1Resource => (new $resource($model))
            ->withPendingApproval(isset($pending[(int) $model->getKey()])));
    }
}
