<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions\Reference;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Models\Country;
use Illuminate\Database\Eloquent\Model;

/** @extends RecordWriter<Country> */
final class CountryWriter extends RecordWriter
{
    protected function model(): string
    {
        return Country::class;
    }

    protected function module(): string
    {
        return 'locations';
    }

    protected function label(): string
    {
        return 'Country';
    }

    protected function columns(): array
    {
        return ['name', 'code', 'flag', 'nationality', 'sort_order', 'is_default', 'is_active'];
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if (isset($values['name']) && $this->taken(Country::query(), ['name' => $values['name']], $exceptId)) {
            throw ApiException::badRequest("A country with the name \"{$values['name']}\" already exists.");
        }
        if (! empty($values['sort_order']) && $this->taken(Country::query(), ['sort_order' => $values['sort_order']], $exceptId)) {
            throw ApiException::badRequest("Sort order {$values['sort_order']} is already taken for countries.");
        }
        if (isset($values['code']) && $this->taken(Country::query(), ['code' => $values['code']], $exceptId)) {
            throw ApiException::conflict('Country with this code already exists');
        }
    }

    protected function revivable(array $values): ?Model
    {
        $old = Country::onlyTrashed()->where('code', $values['code'] ?? null)->first();
        $old?->restore();

        return $old;
    }
}
