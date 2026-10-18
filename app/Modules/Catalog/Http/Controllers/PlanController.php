<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Catalog\Actions\PlanWriter;
use App\Modules\Catalog\Http\Resources\V1\PlanResource;
use App\Modules\Catalog\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/subscription-plans. Filters: event_category_id, plan_type_id,
 * billing_cycle (plus the usual search / is_active).
 *
 * @extends RecordController<Plan>
 */
final class PlanController extends RecordController
{
    private const LIST_RELATIONS = ['planType', 'category', 'planBadge', 'planMenus.menu'];

    protected string $key = 'subscriptionPlan';

    protected string $module = 'subscription_plans';

    protected string $type = 'subscription_plan';

    /** @var list<string> */
    protected array $detail = [...self::LIST_RELATIONS, 'creator', 'updater', 'deactivator', 'deleter'];

    protected array $messages = [
        'created' => 'Subscription plan created successfully',
        'updated' => 'Subscription plan updated successfully',
        'status' => 'Subscription plan status updated successfully',
        'deleted' => 'Subscription plan deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly PlanWriter $writer)
    {
        parent::__construct($approvals);
    }

    public function reasons(): JsonResponse
    {
        return ApiResponse::success(['reasons' => $this->writer->reasons()]);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $plan = $this->writer->deactivate($this->writer->find($id), $this->input($request), $this->actor($request));

        return ApiResponse::success([$this->key => $this->present($this->fresh($plan))], 'Plan deactivated successfully');
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        $plan = $this->writer->reactivate($this->writer->find($id), $this->actor($request));

        return ApiResponse::success([$this->key => $this->present($this->fresh($plan))], 'Plan activated successfully');
    }

    /** POST /subscription-plans/:id/delete { reason, comments } — delete with a reason (approvable). */
    public function deleteWithReason(Request $request, int $id): JsonResponse
    {
        $plan = $this->writer->find($id);
        $input = $this->input($request);
        $actor = $this->actor($request);
        $snapshot = $this->present($this->fresh($plan))->toArray($request);

        return $this->approvals->guard($actor, $this->module, 'delete', $this->type, $plan->id, $input,
            function () use ($plan, $input, $actor, $snapshot): JsonResponse {
                $deleted = $this->writer->deleteWithReason($plan, $input, $actor);

                return ApiResponse::success([$this->key => [
                    ...$snapshot,
                    'deletion_reason' => $deleted->getAttribute('deletion_reason'),
                    'deletion_comments' => $deleted->getAttribute('deletion_comments'),
                    'deleted_at' => ApiResponse::timestamp(),
                ]], 'Plan deleted successfully');
            });
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $plan = $this->writer->duplicate($this->writer->find($id), $this->actor($request));

        return ApiResponse::created([$this->key => $this->present($this->fresh($plan))], 'Subscription plan duplicated successfully');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        $category = $request->query('event_category_id');
        $planType = $request->query('plan_type_id');
        $cycle = $request->query('billing_cycle');

        return (new CallbackListQuery(
            Plan::query()->with(self::LIST_RELATIONS),
            ['name', 'plan_code', 'short_description'],
            ['sort_order', 'name', 'price', 'created_at'],
            true,
            static function (Builder $query) use ($category, $planType, $cycle): void {
                if (is_numeric($category)) {
                    $query->where('event_category_id', (int) $category);
                }
                if (is_numeric($planType)) {
                    $query->where('plan_type_id', (int) $planType);
                }
                if (is_string($cycle) && $cycle !== '' && $cycle !== 'all') {
                    $query->where('billing_cycle', strtolower($cycle));
                }
            },
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new PlanResource($model);
    }
}
