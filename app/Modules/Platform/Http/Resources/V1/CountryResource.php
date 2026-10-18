<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\Country;
use Illuminate\Http\Request;

/**
 * @mixin Country
 *
 * @property Country $resource
 */
final class CountryResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $country = $this->resource;

        return [
            'id' => $country->id,
            'name' => $country->name,
            'code' => $country->code,
            'flag' => $country->getAttribute('flag'),
            'nationality' => $country->getAttribute('nationality'),
            'sort_order' => $country->getAttribute('sort_order'),
            'is_default' => $this->flag($country->getAttribute('is_default')),
            'is_active' => $this->legacyActive($country->is_active, $country->getAttribute('approval_status')),
            'created_at' => $this->isoDate($country->created_at),
            'updated_at' => $this->isoDate($country->updated_at),
            ...$this->pendingApprovalField(),
        ];
    }
}
