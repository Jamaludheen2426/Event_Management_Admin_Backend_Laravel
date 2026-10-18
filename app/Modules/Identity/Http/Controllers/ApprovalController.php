<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Approvals\ApprovalRequest;
use App\Core\Approvals\Approvals;
use App\Core\Approvals\ApprovalStatus;
use App\Core\Exceptions\ApiException;
use App\Modules\Identity\Http\Resources\V1\ApprovalResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * /api/v1/approvals. v1 answered this module WITHOUT the usual envelope
 * ({ success, data, pagination } / { success, count } / { ..., result }) and
 * the approvals screen reads those exact shapes, so they are kept.
 *
 * Requests are company-scoped by the model, so a reviewer can never see or
 * act on another company's request (v1 could, by id).
 */
final class ApprovalController
{
    public function __construct(private readonly Approvals $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', '1'));
        $limit = min(1000, max(1, (int) $request->query('limit', '10') ?: 10));

        $query = ApprovalRequest::query()->latest('created_at');
        $status = ApprovalStatus::fromLegacyFlag($request->query('is_active'));
        if ($status !== null) {
            $query->where('status', $status);
        }
        if (is_string($module = $request->query('module_slug')) && $module !== '') {
            $query->where('module_slug', $module);
        }

        $results = $query->paginate($limit, ['*'], 'page', $page);
        $items = collect($results->items());
        $total = $results->total();
        $totalPages = (int) ceil($total / $limit);

        return $this->raw([
            'success' => true,
            'data' => $this->present($items, $request),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'totalItems' => $total,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
        ]);
    }

    public function pendingCount(): JsonResponse
    {
        return $this->raw(['success' => true, 'count' => ApprovalRequest::query()->pending()->count()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $approval = ApprovalRequest::query()->find($id);
        if ($approval === null) {
            return $this->raw(['success' => false, 'message' => 'Approval request not found'], 404);
        }

        return $this->raw(['success' => true, 'data' => $this->present(collect([$approval]), $request)[0]]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $approval = $this->find($id);
        ['approval' => $approval, 'result' => $result] = $this->approvals->approve(
            $approval,
            $this->actor($request),
            $this->notes($request),
            static fn (int $requesterId): ?User => User::query()->with('roles')->find($requesterId),
        );

        return $this->raw([
            'success' => true,
            'message' => 'Request approved and executed successfully',
            'data' => $this->present(collect([$approval]), $request)[0],
            'result' => $result?->toArray(),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $approval = $this->approvals->reject($this->find($id), $this->actor($request), $this->notes($request));

        return $this->raw([
            'success' => true,
            'message' => 'Request rejected successfully',
            'data' => $this->present(collect([$approval]), $request)[0],
        ]);
    }

    /** The requester withdraws their own open request. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $approval = ApprovalRequest::query()->pending()
            ->where('requester_id', $this->actor($request)->id)
            ->find($id);

        if ($approval === null) {
            return $this->raw(['success' => false, 'message' => 'Approval request not found or cannot be cancelled'], 400);
        }

        $approval->delete();

        return $this->raw(['success' => true, 'message' => 'Request cancelled successfully']);
    }

    /**
     * @param  Collection<int, ApprovalRequest>  $items
     * @return list<array<string, mixed>>
     */
    private function present(Collection $items, Request $request): array
    {
        $ids = $items->pluck('requester_id')->merge($items->pluck('approver_id'))->filter()->unique()->values()->all();
        $users = User::query()->withTrashed()->with('roles')->whereKey($ids)->get()->keyBy('id')->all();

        return $items->map(static fn (ApprovalRequest $a): array => (new ApprovalResource($a))->withUsers($users)->toArray($request))
            ->values()->all();
    }

    private function find(int $id): ApprovalRequest
    {
        return ApprovalRequest::query()->find($id) ?? throw ApiException::notFound('Approval request not found');
    }

    private function notes(Request $request): ?string
    {
        $notes = $request->input('review_notes');

        return is_string($notes) && $notes !== '' ? $notes : null;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /** @param array<string, mixed> $body */
    private function raw(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
