<?php

declare(strict_types=1);

namespace App\Core\Crud;

use App\Core\Activity\ActivityLogger;
use App\Core\Approvals\ApprovalHandler;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Create / update / delete / status / set-default for one table, with the
 * approval workflow. Most admin screens are this: a list of rows somebody
 * creates, edits, switches on and off, and deletes. A subclass names the
 * table's writable columns, normalises them the way v1 did, and states its
 * uniqueness rules with v1's messages; the steps around that live here once.
 *
 * Input arrives in v1 keys; renamed() translates the few that changed.
 * A row created under approval exists at once (approval_status pending, off)
 * so it shows greyed out in lists; approving switches it on.
 *
 * @template TModel of Model
 */
abstract class RecordWriter implements ApprovalHandler
{
    public function __construct(protected readonly ActivityLogger $activity) {}

    /** @return class-string<TModel> */
    abstract protected function model(): string;

    /** Activity-log module, the v1 permission prefix ("event_categories"). */
    abstract protected function module(): string;

    /** Display word for messages and the log ("Event category"). */
    abstract protected function label(): string;

    /** @return list<string> columns a caller may set (after key translation) */
    abstract protected function columns(): array;

    /**
     * Throw when the values clash with another row (v1 messages).
     *
     * @param  array<string, mixed>  $values  on update: the row merged with the change
     */
    protected function assertUnique(array $values, ?int $exceptId): void {}

    /**
     * Normalise and check the caller's values (v1 pickWritable + validation).
     * $existing is null on create.
     *
     * @param  array<string, mixed>  $values
     * @param  TModel|null  $existing
     * @return array<string, mixed>
     */
    protected function prepare(array $values, ?Model $existing): array
    {
        return $values;
    }

    /**
     * Column defaults for a new row that v1 set in code.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * After the row is written, inside the same transaction: pivots, caches.
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $input  the caller's raw input
     */
    protected function afterSave(Model $model, array $input, bool $created): void {}

    /**
     * Rows sharing one "default" flag with this one.
     *
     * @param  array<string, mixed>  $values
     * @return Builder<TModel>
     */
    protected function defaultScope(array $values): Builder
    {
        return $this->query();
    }

    /** @return array<string, string> v1 key => column */
    protected function renamed(): array
    {
        return [];
    }

    /**
     * A soft-deleted row brought back (already restored) instead of inserting
     * a new one, so references to its id stay valid (v1 restored by code).
     *
     * @param  array<string, mixed>  $values
     * @return TModel|null
     */
    protected function revivable(array $values): ?Model
    {
        return null;
    }

    /** @param TModel $model */
    protected function beforeDelete(Model $model): void {}

    /** @param array<string, mixed> $values */
    protected function assertParent(array $values): void {}

    /**
     * Every read and write of this writer goes through this query (add a
     * fixed filter here when several writers share one table).
     *
     * @return Builder<TModel>
     */
    protected function query(): Builder
    {
        return $this->model()::query();
    }

    protected function notFoundMessage(): string
    {
        return "{$this->label()} not found";
    }

    /** @param TModel $model */
    protected function nameOf(Model $model): string
    {
        return (string) ($model->getAttribute('name') ?? $model->getKey());
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return TModel
     */
    public function create(array $input, ?Model $actor, bool $pending = false): Model
    {
        $values = $this->prepare($this->values($input), null);
        $values = [...$this->defaults(), ...$values];
        $this->assertParent($values);
        $this->assertUnique($values, null);

        return DB::transaction(function () use ($values, $input, $actor, $pending): Model {
            if (! empty($values['is_default'])) {
                $this->defaultScope($values)->update(['is_default' => false]);
            }

            $values['is_active'] = $pending ? 0 : Flag::bit($values['is_active'] ?? null, 1);
            $values['approval_status'] = $pending ? 'pending' : 'approved';
            $values['created_by'] = $actor?->getKey();

            $model = $this->revivable($values);
            if ($model !== null) {
                $model->fill($values)->save();
            } else {
                $model = $this->query()->create($values);
            }
            $this->afterSave($model, $input, true);

            $this->activity->log($this->module(), 'create', "Created {$this->label()}: {$this->nameOf($model)}", $model, [], $values);

            return $model;
        });
    }

    /**
     * @param  TModel  $model
     * @param  array<string, mixed>  $input
     * @return TModel
     */
    public function update(Model $model, array $input, ?Model $actor): Model
    {
        $values = $this->prepare($this->values($input), $model);
        $merged = [...$model->attributesToArray(), ...$values];
        $this->assertParent($merged);
        $this->assertUnique($merged, (int) $model->getKey());

        return DB::transaction(function () use ($model, $values, $merged, $input, $actor): Model {
            if (! empty($values['is_default'])) {
                $this->defaultScope($merged)->whereKeyNot($model->getKey())->update(['is_default' => false]);
            }
            if (array_key_exists('is_active', $values)) {
                $values['is_active'] = Flag::bit($values['is_active'], 1);
            }

            $old = $model->only(array_keys($values));
            $model->fill([...$values, 'updated_by' => $actor?->getKey()])->save();
            $this->afterSave($model, $input, false);

            $this->activity->log($this->module(), 'update', "Updated {$this->label()}: {$this->nameOf($model)}", $model, $old, $values);

            return $model;
        });
    }

    /**
     * The list's on/off switch (v1 PATCH /:id/status). Not an approvable change in v1.
     *
     * @param  TModel  $model
     * @return TModel
     */
    public function setActive(Model $model, mixed $isActive, ?Model $actor): Model
    {
        return $this->update($model, ['is_active' => Flag::bit($isActive, 1)], $actor);
    }

    /** @param TModel $model */
    public function delete(Model $model, ?Model $actor): void
    {
        DB::transaction(function () use ($model, $actor): void {
            $this->beforeDelete($model);
            $model->forceFill(['updated_by' => $actor?->getKey()])->save();
            $model->delete();
        });

        $this->activity->log($this->module(), 'delete', "Deleted {$this->label()}: {$this->nameOf($model)}", $model);
    }

    /**
     * @param  TModel  $model
     * @return TModel
     */
    public function setDefault(Model $model, ?Model $actor): Model
    {
        DB::transaction(function () use ($model, $actor): void {
            $this->defaultScope($model->attributesToArray())->whereKeyNot($model->getKey())->update(['is_default' => false]);
            $model->forceFill(['is_default' => true, 'updated_by' => $actor?->getKey()])->save();
        });

        $this->activity->log($this->module(), 'set_default', "Set default {$this->label()}: {$this->nameOf($model)}", $model);

        return $model;
    }

    /**
     * v1 "Change Order": [{id, sort_order}], rows of other tenants ignored,
     * one transaction (a half-applied reorder leaves two rows on one position).
     *
     * @param  mixed  $items  the raw body value
     * @return array{updated: int}
     */
    public function reorder(mixed $items, ?Model $actor): array
    {
        if (! is_array($items) || $items === []) {
            throw ApiException::badRequest('Provide an array of { id, sort_order }.');
        }

        $positions = [];
        foreach ($items as $item) {
            if (is_array($item) && is_numeric($item['id'] ?? null)) {
                $positions[(int) $item['id']] = (int) ($item['sort_order'] ?? 0);
            }
        }
        $owned = $this->query()->whereKey(array_keys($positions))->pluck('id')->all();

        DB::transaction(function () use ($owned, $positions, $actor): void {
            foreach ($owned as $id) {
                $this->query()->whereKey($id)->update(['sort_order' => $positions[(int) $id], 'updated_by' => $actor?->getKey()]);
            }
        });

        return ['updated' => count($owned)];
    }

    /** @return TModel */
    public function find(int $id): Model
    {
        return $this->query()->find($id) ?? throw ApiException::notFound($this->notFoundMessage());
    }

    // ---- ApprovalHandler ------------------------------------------------

    public function snapshot(int $id): ?array
    {
        return $this->query()->find($id)?->attributesToArray();
    }

    public function createPending(array $payload, Model $requester): int
    {
        return (int) $this->create($payload, $requester, pending: true)->getKey();
    }

    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model
    {
        $model = $resourceId === null ? null : $this->query()->find($resourceId);
        if ($model === null) {
            throw ApiException::notFound($this->notFoundMessage());
        }

        if ($action === 'create') {
            $model->forceFill(['approval_status' => 'approved', 'is_active' => 1])->save();

            return $model;
        }

        if ($action === 'delete') {
            $this->delete($model, $requester);

            return null;
        }

        return $this->update($model, $payload, $requester);
    }

    public function discard(int $resourceId, Model $reviewer): void
    {
        $this->query()->whereKey($resourceId)->where('approval_status', 'pending')->first()?->forceDelete();
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function values(array $input): array
    {
        $renamed = $this->renamed();
        $values = [];
        foreach ($input as $key => $value) {
            $column = $renamed[$key] ?? $key;
            if (in_array($column, $this->columns(), true)) {
                $values[$column] = $value;
            }
        }
        // v1 tri-state is_active: 2 was "pending", which only the workflow sets.
        if (array_key_exists('is_active', $values) && (string) $values['is_active'] === '2') {
            unset($values['is_active']);
        }

        return $values;
    }

    /**
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $where
     */
    protected function taken(Builder $query, array $where, ?int $exceptId): bool
    {
        foreach ($where as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->when($exceptId !== null, static fn (Builder $q) => $q->whereKeyNot($exceptId))->exists();
    }

    /**
     * v1 buildUniqueSlug(): the slug, or slug-2, slug-3... when taken by a live row.
     */
    protected function uniqueSlug(string $base, string $column, ?int $exceptId, string $fallback): string
    {
        $root = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($base))), '-') ?: $fallback;
        $taken = array_flip($this->query()
            ->where($column, 'like', $root.'%')
            ->when($exceptId !== null, static fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->pluck($column)->all());

        if (! isset($taken[$root])) {
            return $root;
        }
        for ($i = 2; $i < 1000; $i++) {
            if (! isset($taken["{$root}-{$i}"])) {
                return "{$root}-{$i}";
            }
        }

        return $root.'-'.time();
    }
}
