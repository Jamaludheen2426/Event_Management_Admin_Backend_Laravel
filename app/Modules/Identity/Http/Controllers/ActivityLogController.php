<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Activity\Activity;
use App\Core\Http\ApiResponse;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Http\Resources\V1\ActivityResource;
use App\Modules\Identity\Queries\ActivityListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/activity-logs — the current company's audit trail. */
final class ActivityLogController
{
    public function index(Request $request, ActivityListQuery $list): JsonResponse
    {
        return ApiResponse::paginated($list->paginate($request), ActivityResource::class);
    }

    public function byUser(Request $request, ActivityListQuery $list, int $userId): JsonResponse
    {
        return ApiResponse::paginated($list->forUser($userId)->paginate($request), ActivityResource::class);
    }

    public function byModule(Request $request, ActivityListQuery $list, string $module): JsonResponse
    {
        return ApiResponse::paginated($list->forModule($module)->paginate($request), ActivityResource::class);
    }

    /** DELETE /activity-logs/clear?days=90 */
    public function clear(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->query('days', '90'));
        $deleted = Activity::query()
            ->where('company_id', Tenancy::companyId())
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        return ApiResponse::success(['deleted' => $deleted], "Cleared {$deleted} activity logs older than {$days} days");
    }
}
