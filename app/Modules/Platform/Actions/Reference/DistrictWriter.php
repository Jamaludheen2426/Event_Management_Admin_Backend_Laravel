<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Reference;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Models\District;
use App\Modules\Platform\Models\State;
use Illuminate\Database\Eloquent\Model;

/** @extends RecordWriter<District> */
final class DistrictWriter extends RecordWriter
{
    protected function model(): string
    {
        return District::class;
    }

    protected function module(): string
    {
        return 'locations';
    }

    protected function label(): string
    {
        return 'District';
    }

    protected function columns(): array
    {
        return ['state_id', 'name', 'slug', 'sort_order', 'is_default', 'is_active'];
    }

    protected function assertParent(array $values): void
    {
        if (! State::query()->whereKey((int) ($values['state_id'] ?? 0))->exists()) {
            throw ApiException::badRequest('Invalid state');
        }
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        $state = (int) $values['state_id'];
        if (isset($values['name']) && $this->taken(District::query(), ['state_id' => $state, 'name' => $values['name']], $exceptId)) {
            throw ApiException::badRequest("A district with the name \"{$values['name']}\" already exists in this state.");
        }
        if (! empty($values['sort_order']) && $this->taken(District::query(), ['state_id' => $state, 'sort_order' => $values['sort_order']], $exceptId)) {
            throw ApiException::badRequest("Sort order {$values['sort_order']} is already taken in this state.");
        }
    }

    protected function revivable(array $values): ?Model
    {
        if (empty($values['slug'])) {
            return null;
        }

        $old = District::onlyTrashed()->where('slug', $values['slug'])->first();
        $old?->restore();

        return $old;
    }
}
