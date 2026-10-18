<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\District;
use Illuminate\Http\Request;

/**
 * v1 districts carried their own country_id; it is derived from the state now.
 *
 * @mixin District
 *
 * @property District $resource
 */
final class DistrictResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $district = $this->resource;
        $state = $district->relationLoaded('state') ? $district->state : null;

        return [
            'id' => $district->id,
            'state_id' => $district->state_id,
            'country_id' => $state?->country_id,
            'name' => $district->name,
            'slug' => $district->getAttribute('slug'),
            'sort_order' => $district->getAttribute('sort_order'),
            'is_default' => $this->flag($district->getAttribute('is_default')),
            'is_active' => $this->legacyActive($district->is_active, $district->getAttribute('approval_status')),
            'created_at' => $this->isoDate($district->created_at),
            'updated_at' => $this->isoDate($district->updated_at),
            'state' => $this->when($state !== null, fn (): array => [
                'id' => $state?->id, 'name' => $state?->name, 'country_id' => $state?->country_id,
            ]),
            ...$this->pendingApprovalField(),
        ];
    }
}
