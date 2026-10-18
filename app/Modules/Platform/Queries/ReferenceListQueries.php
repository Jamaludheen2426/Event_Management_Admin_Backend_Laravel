<?php

declare(strict_types=1);

namespace App\Modules\Platform\Queries;

use App\Core\Queries\CallbackListQuery;
use App\Modules\Platform\Models\City;
use App\Modules\Platform\Models\Country;
use App\Modules\Platform\Models\Currency;
use App\Modules\Platform\Models\District;
use App\Modules\Platform\Models\Language;
use App\Modules\Platform\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * List queries of the reference tables (all approvable). Search columns,
 * sort columns and filters are v1's.
 */
final class ReferenceListQueries
{
    /** @return CallbackListQuery<Language> */
    public static function languages(): CallbackListQuery
    {
        return new CallbackListQuery(Language::query(), ['name', 'code', 'native_name'], ['created_at', 'name', 'code'], true);
    }

    /** @return CallbackListQuery<Currency> */
    public static function currencies(): CallbackListQuery
    {
        return new CallbackListQuery(Currency::query(), ['name', 'code', 'symbol'], ['created_at', 'name', 'code', 'exchange_rate'], true);
    }

    /** @return CallbackListQuery<Country> */
    public static function countries(): CallbackListQuery
    {
        return new CallbackListQuery(Country::query(), ['name', 'code', 'nationality'], ['created_at', 'name', 'code', 'sort_order'], true);
    }

    /**
     * ?has_districts=1 keeps states that have districts.
     *
     * @return CallbackListQuery<State>
     */
    public static function states(?int $countryId): CallbackListQuery
    {
        return new CallbackListQuery(State::query()->with('country'), ['name', 'code', 'slug'], ['created_at', 'name', 'sort_order'], true,
            static function (Builder $query, Request $request) use ($countryId): void {
                if ($countryId !== null) {
                    $query->where('country_id', $countryId);
                }
                if ($request->boolean('has_districts')) {
                    $query->whereHas('districts');
                }
            });
    }

    /**
     * ?country_id (when no state), ?has_cities=1.
     *
     * @return CallbackListQuery<District>
     */
    public static function districts(?int $stateId): CallbackListQuery
    {
        return new CallbackListQuery(District::query()->with('state'), ['name', 'slug'], ['created_at', 'name', 'sort_order'], true,
            static function (Builder $query, Request $request) use ($stateId): void {
                if ($stateId !== null) {
                    $query->where('state_id', $stateId);
                } elseif (is_numeric($request->query('country_id'))) {
                    $country = (int) $request->query('country_id');
                    $query->whereHas('state', static fn (Builder $s) => $s->where('country_id', $country));
                }
                if ($request->boolean('has_cities')) {
                    $query->whereHas('cities');
                }
            });
    }

    /**
     * ?state_id or ?country_id when no district is given.
     *
     * @return CallbackListQuery<City>
     */
    public static function cities(?int $districtId): CallbackListQuery
    {
        return new CallbackListQuery(City::query()->with('district.state.country'), ['name', 'pincode'], ['created_at', 'name', 'pincode'], true,
            static function (Builder $query, Request $request) use ($districtId): void {
                if ($districtId !== null) {
                    $query->where('district_id', $districtId);
                } elseif (is_numeric($request->query('state_id'))) {
                    $state = (int) $request->query('state_id');
                    $query->whereHas('district', static fn (Builder $d) => $d->where('state_id', $state));
                } elseif (is_numeric($request->query('country_id'))) {
                    $country = (int) $request->query('country_id');
                    $query->whereHas('district.state', static fn (Builder $s) => $s->where('country_id', $country));
                }
            });
    }
}
