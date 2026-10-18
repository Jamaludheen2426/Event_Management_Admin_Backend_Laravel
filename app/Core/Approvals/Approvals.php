<?php

declare(strict_types=1);

namespace App\Core\Approvals;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The approval workflow.
 *
 * A controller wraps the real work in guard(). If the actor's role says the
 * change needs approval, the change is parked as an ApprovalRequest and the API
 * answers 202 (v1 shape); otherwise the work runs immediately.
 *
 * Unlike the Node middleware, guard() runs AFTER the FormRequest validated the
 * input, so a request that would fail validation is never sent for approval.
 */
final class Approvals
{
    public function __construct(
        private readonly ApprovalRegistry $registry,
        private readonly ApprovalPolicy $policy,
    ) {}

    /**
     * @template T
     *
     * @param  array<string, mixed>  $payload  validated input
     * @param  Closure(): T  $execute  the change, when no approval is needed
     * @return T|JsonResponse
     */
    public function guard(
        Model $actor,
        string $module,
        string $action,
        string $resourceType,
        ?int $resourceId,
        array $payload,
        Closure $execute,
    ): mixed {
        if (! $this->policy->requiresApproval($actor, $module, $action)) {
            return $execute();
        }

        $handler = $this->registry->handler($module, $resourceType);

        $old = null;
        if ($resourceId !== null && in_array($action, ['edit', 'update', 'delete'], true)) {
            $old = $handler->snapshot($resourceId);
        }

        // A status toggle sends no body; record the intended flip so the
        // reviewer sees what will happen.
        if ($payload === [] && is_array($old) && array_key_exists('is_active', $old)) {
            $payload = ['is_active' => ! (bool) $old['is_active']];
        }

        $request = DB::transaction(function () use ($handler, $actor, $module, $action, $resourceType, $resourceId, $payload, $old): ApprovalRequest {
            if ($action === 'create') {
                $resourceId = $handler->createPending($payload, $actor);
            }

            // One open request per resource and action.
            ApprovalRequest::query()->pending()
                ->where('module_slug', $module)
                ->where('action', $action)
                ->where('resource_type', $resourceType)
                ->when($resourceId !== null, fn ($q) => $q->where('resource_id', $resourceId))
                ->delete();

            return ApprovalRequest::query()->create([
                'requester_id' => $actor->getKey(),
                'module_slug' => $module,
                'permission_slug' => "{$module}.{$action}",
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'request_data' => $payload,
                'old_data' => $old,
                'status' => ApprovalStatus::Pending,
            ]);
        });

        return ApiResponse::pendingApproval($request->id, $action);
    }

    /**
     * Approve and run the change as the requester.
     *
     * @return array{approval: ApprovalRequest, result: Model|null}
     */
    public function approve(ApprovalRequest $request, Model $reviewer, ?string $notes, Closure $resolveRequester): array
    {
        $this->assertOpen($request);

        return DB::transaction(function () use ($request, $reviewer, $notes, $resolveRequester): array {
            $requester = $resolveRequester($request->requester_id) ?? $reviewer;
            $handler = $this->registry->handler($request->module_slug, $request->resource_type);

            try {
                $result = $handler->apply($request->action, $request->resource_id, $request->request_data ?? [], $requester);
            } catch (ApiException $e) {
                throw ApiException::badRequest('Approval failed during execution: '.$e->getMessage());
            }

            $request->fill([
                'status' => ApprovalStatus::Approved,
                'approver_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);
            if ($result !== null && $request->resource_id === null) {
                $request->resource_id = (int) $result->getKey();
            }
            $request->save();

            return ['approval' => $request, 'result' => $result];
        });
    }

    public function reject(ApprovalRequest $request, Model $reviewer, ?string $notes): ApprovalRequest
    {
        $this->assertOpen($request);

        return DB::transaction(function () use ($request, $reviewer, $notes): ApprovalRequest {
            if ($request->action === 'create' && $request->resource_id !== null
                && $this->registry->has($request->module_slug, $request->resource_type)) {
                $this->registry->handler($request->module_slug, $request->resource_type)
                    ->discard($request->resource_id, $reviewer);
            }

            $request->fill([
                'status' => ApprovalStatus::Rejected,
                'approver_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();

            return $request;
        });
    }

    public function canReview(Model $actor): bool
    {
        return $this->policy->canReview($actor);
    }

    private function assertOpen(ApprovalRequest $request): void
    {
        if ($request->status !== ApprovalStatus::Pending) {
            throw ApiException::badRequest('Request has already been reviewed');
        }
    }
}
