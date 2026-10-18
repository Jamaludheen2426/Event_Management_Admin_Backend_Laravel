<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Save a drag-and-drop order in one statement (v1 CASE id WHEN ... END).
 * Ids the caller sent come first, in their order; any row they left out keeps
 * its place after them. Unknown ids are ignored.
 */
final class Ordering
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $rows  the rows being ordered, in their current order
     */
    public static function apply(Builder $rows, mixed $ids, string $column = 'sort_order'): void
    {
        $current = (clone $rows)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $sent = array_values(array_intersect(array_unique(array_map('intval', is_array($ids) ? $ids : [])), $current));
        $order = [...$sent, ...array_values(array_diff($current, $sent))];
        if ($order === []) {
            return;
        }

        $cases = implode(' ', array_map(static fn (int $id, int $i): string => "when {$id} then {$i}", $order, array_keys($order)));
        (clone $rows)->getQuery()->reorder()->whereIn('id', $order)->update([$column => $rows->getQuery()->raw("case id {$cases} end")]);
    }
}
