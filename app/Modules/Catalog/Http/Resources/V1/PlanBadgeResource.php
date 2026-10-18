<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanBadge;
use Illuminate\Http\Request;

/**
 * Badge row + plans [{id, name, plan_code}] + plan_ids.
 *
 * @property PlanBadge $resource
 */
final class PlanBadgeResource extends RowResource
{
    protected function extra(Request $request): array
    {
        $plans = $this->resource->relationLoaded('plans') ? $this->resource->plans : collect();

        return [
            'plans' => $plans->map(static fn (Plan $p): array => $p->only(['id', 'name', 'plan_code']))->values()->all(),
            'plan_ids' => $plans->pluck('id')->values()->all(),
        ];
    }
}
