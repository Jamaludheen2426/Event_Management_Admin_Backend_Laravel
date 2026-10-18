<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use App\Modules\Catalog\Models\PlanType;
use Illuminate\Http\Request;

/**
 * Plan type row; the menus it unlocks travel as v1's menu_ids array.
 *
 * @property PlanType $resource
 */
final class PlanTypeResource extends RowResource
{
    protected function extra(Request $request): array
    {
        return ['menu_ids' => $this->resource->relationLoaded('menus') ? $this->resource->menus->pluck('id')->values()->all() : []];
    }
}
