<?php

declare(strict_types=1);

namespace App\Core\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * For tables whose columns kept their v1 names: v1 answered such rows as the
 * whole row (Sequelize toJSON), so this answers every column, with:
 *
 *  - dates in v1's ISO format (the models' serializeDate);
 *  - is_active as v1's tri-state (2 while waiting for approval);
 *  - internal columns (alive, approval_status) left out;
 *  - has_pending_approval on list rows;
 *  - extra() for relations and computed keys a module adds.
 *
 * @property Model $resource
 */
class RowResource extends V1Resource
{
    /** Columns never sent. */
    protected const HIDDEN = ['alive', 'approval_status'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $model = $this->resource;
        $row = array_diff_key($model->attributesToArray(), array_flip(static::HIDDEN));

        if (array_key_exists('is_active', $row)) {
            $row['is_active'] = $this->legacyActive($row['is_active'], $model->getAttributes()['approval_status'] ?? null);
        }

        return [...$row, ...$this->extra($request), ...$this->pendingApprovalField()];
    }

    /** @return array<string, mixed> */
    protected function extra(Request $request): array
    {
        return [];
    }

    /**
     * A loaded belongs-to relation as v1's small include ({id, name, color}),
     * null when empty. Absent (not loaded) relations are left out entirely.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    protected function brief(string $relation, array $fields, ?string $as = null): array
    {
        if (! $this->resource->relationLoaded($relation)) {
            return [];
        }
        $related = $this->resource->getRelation($relation);

        return [$as ?? $relation => $related instanceof Model ? $related->only($fields) : null];
    }

    /**
     * creator / updater, when loaded (detail screens).
     *
     * @return array<string, mixed>
     */
    protected function authors(string ...$relations): array
    {
        $out = [];
        foreach ($relations === [] ? ['creator', 'updater'] : $relations as $relation) {
            if ($this->resource->relationLoaded($relation)) {
                $out[$relation] = $this->person($relation);
            }
        }

        return $out;
    }

    /**
     * v1's `creator` / `updater` includes on detail screens.
     *
     * @return array{id: int, full_name: string}|null
     */
    protected function person(string $relation): ?array
    {
        if (! $this->resource->relationLoaded($relation)) {
            return null;
        }
        $user = $this->resource->getRelation($relation);

        return $user instanceof Model ? ['id' => (int) $user->getKey(), 'full_name' => (string) $user->getAttribute('full_name')] : null;
    }
}
