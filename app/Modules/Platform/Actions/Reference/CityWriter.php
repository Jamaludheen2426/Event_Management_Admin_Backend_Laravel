<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Reference;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Models\City;
use App\Modules\Platform\Models\District;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cities (localities with a pincode). v1 sent the parent district as
 * `city_id`; it is district_id now.
 *
 * @extends RecordWriter<City>
 */
final class CityWriter extends RecordWriter
{
    protected function model(): string
    {
        return City::class;
    }

    protected function module(): string
    {
        return 'locations';
    }

    protected function label(): string
    {
        return 'City';
    }

    protected function columns(): array
    {
        return ['district_id', 'name', 'pincode', 'is_default', 'is_active'];
    }

    protected function renamed(): array
    {
        return ['city_id' => 'district_id'];
    }

    protected function assertParent(array $values): void
    {
        if (! District::query()->whereKey((int) ($values['district_id'] ?? 0))->exists()) {
            throw ApiException::badRequest('Invalid district');
        }
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if (isset($values['name']) && $this->taken(City::query(), ['district_id' => (int) $values['district_id'], 'name' => $values['name']], $exceptId)) {
            throw ApiException::badRequest("A city with the name \"{$values['name']}\" already exists in this district.");
        }
    }

    /** The default city is per district (v1). */
    protected function defaultScope(array $values): Builder
    {
        return City::query()->where('district_id', (int) ($values['district_id'] ?? 0));
    }
}
