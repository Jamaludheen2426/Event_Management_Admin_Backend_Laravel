<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Identity\Actions\Users\UserWriter;
use App\Modules\Identity\Http\Requests\UserRequest;
use App\Modules\Identity\Http\Resources\V1\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Queries\UserListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/users — admin employees (permission module "employees").
 * Create, edit and delete go through approval when the editor's role says so.
 */
final class UserController
{
    private const DETAIL = ['roles.permissions.group', 'department', 'country', 'state', 'district', 'city'];

    public function __construct(
        private readonly UserWriter $writer,
        private readonly Approvals $approvals,
    ) {}

    public function index(Request $request, UserListQuery $list): JsonResponse
    {
        return ApiResponse::paginated(PendingApprovals::annotate($list->paginate($request), UserResource::class, 'employees', 'employee'));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['user' => new UserResource($this->find($id)->load(self::DETAIL))]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'employees', 'create', 'employee', null, $request->validated(),
            fn (): JsonResponse => ApiResponse::created(
                ['user' => new UserResource($this->writer->create($request->validated(), $actor)->load(self::DETAIL))],
                'User created successfully',
            ));
    }

    public function update(UserRequest $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $user = $this->find($id);

        return $this->approvals->guard($actor, 'employees', 'edit', 'employee', $user->id, $request->validated(),
            fn (): JsonResponse => ApiResponse::success(
                ['user' => new UserResource($this->writer->update($user, $request->validated(), $actor)->load(self::DETAIL))],
                'User updated successfully',
            ));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $user = $this->find($id);

        return $this->approvals->guard($actor, 'employees', 'delete', 'employee', $user->id, [],
            function () use ($user, $actor): JsonResponse {
                $this->writer->delete($user, $actor);

                return ApiResponse::success(null, 'User deleted successfully');
            });
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $isActive = $request->input('is_active');
        if (! in_array((string) $isActive, ['0', '1', 'true', 'false'], true)) {
            throw ApiException::badRequest('is_active must be 0 or 1');
        }

        $user = $this->writer->setStatus($this->find($id), in_array((string) $isActive, ['1', 'true'], true) ? 1 : 0, $this->actor($request));

        return ApiResponse::success(['user' => new UserResource($user->load(['roles', 'department']))], 'User status updated successfully');
    }

    private function find(int $id): User
    {
        return User::query()->find($id) ?? throw ApiException::notFound('User not found');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
