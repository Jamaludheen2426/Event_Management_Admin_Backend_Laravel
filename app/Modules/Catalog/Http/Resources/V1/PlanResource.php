<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use Illuminate\Http\Request;

/**
 * Subscription plan in v1 keys: the row, planType / category / planBadge,
 * planMenus (each with its menu; v1 pivot key menu_id), is_trial and
 * total_menus; creator / updater / deactivator / deleter on detail.
 *
 * @property Plan $resource
 */
final class PlanResource extends RowResource
{
    protected function extra(Request $request): array
    {
        $plan = $this->resource;
        $menus = $plan->relationLoaded('planMenus') ? $plan->planMenus : null;

        return [
            ...$this->brief('planType', ['id', 'name']),
            ...$this->brief('category', ['id', 'name', 'color']),
            ...$this->brief('planBadge', ['id', 'text', 'style', 'color']),
            ...($menus === null ? [] : ['planMenus' => $menus->map(static fn (PlanMenu $m): array => [
                'id' => $m->id,
                'plan_id' => $m->plan_id,
                'menu_id' => $m->event_menu_id,
                'sort_order' => $m->sort_order,
                'created_at' => $m->getAttribute('created_at')?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'updated_at' => $m->getAttribute('updated_at')?->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'menu' => $m->relationLoaded('menu') && $m->menu !== null
                    ? $m->menu->only(['id', 'name', 'slug', 'menu_group', 'icon', 'color'])
                    : null,
            ])->values()->all()]),
            'is_trial' => (float) $plan->price === 0.0 && (int) $plan->trial_days > 0,
            'total_menus' => $menus?->count() ?? 0,
            ...$this->authors('creator', 'updater', 'deactivator', 'deleter'),
        ];
    }
}
