<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\City;
use Illuminate\Http\Request;

/**
 * v1 city: the parent district travels as `city_id`, with the nested
 * district -> state -> country chain the location screens show.
 *
 * @mixin City
 *
 * @property City $resource
 */
final class CityResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $city = $this->resource;
        $district = $city->relationLoaded('district') ? $city->district : null;
        $state = $district?->relationLoaded('state') ? $district->state : null;
        $country = $state?->relationLoaded('country') ? $state->country : null;

        return [
            'id' => $city->id,
            'city_id' => $city->district_id,
            'name' => $city->name,
            'pincode' => $city->pincode,
            'is_default' => $this->flag($city->getAttribute('is_default')),
            'is_active' => $this->legacyActive($city->is_active, $city->getAttribute('approval_status')),
            'created_at' => $this->isoDate($city->created_at),
            'updated_at' => $this->isoDate($city->updated_at),
            'district' => $this->when($district !== null, fn (): array => [
                'id' => $district?->id,
                'name' => $district?->name,
                'state_id' => $district?->state_id,
                'country_id' => $state?->country_id,
                'state' => $state === null ? null : [
                    'id' => $state->id,
                    'name' => $state->name,
                    'country_id' => $state->country_id,
                    'country' => $country === null ? null : ['id' => $country->id, 'name' => $country->name],
                ],
            ]),
            ...$this->pendingApprovalField(),
        ];
    }
}
