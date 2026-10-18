<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\GuestOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One guest registration dropdown (v1 guestOption.service build()). Both
 * dropdowns live in guest_options; each writer only ever sees its own kind.
 * A name is unique within its list (same kind, same event category).
 *
 * @extends RecordWriter<GuestOption>
 */
abstract class GuestOptionWriter extends RecordWriter
{
    /** relationship | food_preference */
    abstract public function kind(): string;

    /** "relationship" / "food preference" for messages */
    abstract protected function noun(): string;

    protected function model(): string
    {
        return GuestOption::class;
    }

    protected function query(): Builder
    {
        return GuestOption::query()->where('kind', $this->kind());
    }

    protected function columns(): array
    {
        return ['event_category_id', 'name', 'description', 'icon', 'color', 'sort_order', 'is_active'];
    }

    protected function defaults(): array
    {
        return ['kind' => $this->kind()];
    }

    protected function notFoundMessage(): string
    {
        return "That {$this->noun()} was not found.";
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if ($existing === null || array_key_exists('name', $values)) {
            $values['name'] = trim((string) ($values['name'] ?? ''));
            if ($values['name'] === '') {
                throw ApiException::badRequest("A {$this->noun()} name is required.");
            }
        }
        if (array_key_exists('event_category_id', $values) || $existing === null) {
            $values['event_category_id'] = Flag::id($values['event_category_id'] ?? null);
            if ($values['event_category_id'] !== null && ! EventCategory::query()->whereKey($values['event_category_id'])->exists()) {
                throw ApiException::badRequest('That event category does not exist.');
            }
        }

        return $values;
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        $where = ['name' => $values['name'], 'event_category_id' => $values['event_category_id'] ?? null];
        if ($this->taken($this->query(), $where, $exceptId)) {
            throw ApiException::badRequest("\"{$values['name']}\" is already in this list.");
        }
    }
}
