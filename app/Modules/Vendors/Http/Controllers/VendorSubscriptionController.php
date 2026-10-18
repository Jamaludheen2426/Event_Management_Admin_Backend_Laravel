<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Catalog\Http\Resources\V1\PlanTypeResource;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /vendors/subscription — the vendor's own tier with the platform.
 *
 * v1 read a `Subscription` model that did not exist, so this endpoint always
 * crashed (bug 2). The model it meant is plan_types: a custom tier made for
 * this vendor wins; otherwise the common tier matching the vendor's
 * membership; otherwise all common tiers.
 */
final class VendorSubscriptionController
{
    public function show(Request $request): JsonResponse
    {
        /** @var VendorUser $owner */
        $owner = $request->user();
        $vendor = $owner->vendor;

        $custom = PlanType::query()->where('is_custom', 1)->where('vendor_id', $vendor->id)->where('is_active', 1)->with('menus')->first();
        $common = PlanType::query()->where('is_custom', 0)->where('is_active', 1)->with('menus')
            ->orderBy('sort_order')->orderBy('price')->get();
        $present = static fn (iterable $types): array => collect($types)->map(static fn (PlanType $t): PlanTypeResource => new PlanTypeResource($t))->values()->all();

        if ($custom !== null) {
            return ApiResponse::success(['type' => 'custom', 'plans' => $present([$custom]), 'all_plans' => $present([$custom, ...$common])], 'Subscription retrieved');
        }

        $mine = $common->firstWhere('id', $vendor->getAttribute('plan_type_id'));
        if ($mine !== null) {
            return ApiResponse::success(['type' => 'common', 'plans' => $present([$mine]), 'all_plans' => $present($common)], 'Subscription retrieved');
        }

        return ApiResponse::success(['type' => 'common', 'plans' => $present($common), 'all_plans' => $present($common)], 'Subscription plans retrieved');
    }
}
