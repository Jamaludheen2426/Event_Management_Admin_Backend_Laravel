<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Reference;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Models\Country;
use App\Modules\Platform\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends RecordWriter<State> */
final class StateWriter extends RecordWriter
{
    protected function model(): string
    {
        return State::class;
    }

    protected function module(): string
    {
        return 'locations';
    }

    protected function label(): string
    {
        return 'State';
    }

    protected function columns(): array
    {
        return ['country_id', 'name', 'code', 'slug', 'sort_order', 'is_default', 'is_active'];
    }

    protected function assertParent(array $values): void
    {
        if (! Country::query()->whereKey((int) ($values['country_id'] ?? 0))->exists()) {
            throw ApiException::badRequest('Invalid country');
        }
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        $country = (int) $values['country_id'];
        if (isset($values['name']) && $this->taken(State::query(), ['country_id' => $country, 'name' => $values['name']], $exceptId)) {
            throw ApiException::badRequest("A state with the name \"{$values['name']}\" already exists in this country.");
        }
        if (! empty($values['sort_order']) && $this->taken(State::query(), ['country_id' => $country, 'sort_order' => $values['sort_order']], $exceptId)) {
            throw ApiException::badRequest("Sort order {$values['sort_order']} is already taken in this country.");
        }
    }

    /** v1 "default" state was global, not per country; kept. */
    protected function defaultScope(array $values): Builder
    {
        return State::query();
    }

    protected function revivable(array $values): ?Model
    {
        if (empty($values['code'])) {
            return null;
        }

        $old = State::onlyTrashed()->where('country_id', $values['country_id'])->where('code', $values['code'])->first();
        $old?->restore();

        return $old;
    }
}
