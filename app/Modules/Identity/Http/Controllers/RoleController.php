<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Identity\Actions\Roles\RoleWriter;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Http\Resources\V1\RoleResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Queries\RoleListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/roles — admin roles of the current company. */
final class RoleController
{
    private const DETAIL = ['permissions.group'];

    public function __construct(
        private readonly RoleWriter $writer,
        private readonly Approvals $approvals,
    ) {}

    public function index(Request $request, RoleListQuery $list): JsonResponse
    {
        return ApiResponse::paginated(PendingApprovals::annotate($list->paginate($request), RoleResource::class, 'roles', 'role'));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['role' => new RoleResource($this->find($id)->load(self::DETAIL))]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        /** @var array{name: string, slug?: string|null, description?: string|null, level?: int|null} $input */
        $input = $request->validated();

        return $this->approvals->guard($actor, 'roles', 'create', 'role', null, $input,
            fn (): JsonResponse => ApiResponse::created(
                ['role' => new RoleResource($this->writer->create($input, $actor)->load(self::DETAIL))],
                'Role created successfully',
            ));
    }

    public function update(RoleRequest $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $role = $this->find($id);

        return $this->approvals->guard($actor, 'roles', 'update', 'role', $role->id, $request->validated(),
            fn (): JsonResponse => ApiResponse::success(
                ['role' => new RoleResource($this->writer->update($role, $request->validated(), $actor)->load(self::DETAIL))],
                'Role updated successfully',
            ));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $role = $this->find($id);

        return $this->approvals->guard($actor, 'roles', 'delete', 'role', $role->id, [],
            function () use ($role, $actor): JsonResponse {
                $this->writer->delete($role, $actor);

                return ApiResponse::success(null, 'Role deleted successfully');
            });
    }

    public function assignPermissions(Request $request, int $id): JsonResponse
    {
        $grants = $this->grants($request);
        $role = $this->writer->assignPermissions($this->find($id), $grants, $this->actor($request));

        return ApiResponse::success(['role' => new RoleResource($role->load(self::DETAIL))], 'Permissions assigned successfully');
    }

    /** @return list<array{permission_id: int, requires_approval: bool}> */
    private function grants(Request $request): array
    {
        $raw = $request->input('permissions', $request->input('permission_ids', []));
        if (! is_array($raw)) {
            throw ApiException::badRequest('permissions must be an array');
        }

        $grants = [];
        foreach ($raw as $item) {
            $id = is_array($item) ? ($item['permission_id'] ?? null) : $item;
            if (! is_numeric($id)) {
                throw ApiException::badRequest('Some permissions are invalid or belong to another company');
            }
            $grants[] = [
                'permission_id' => (int) $id,
                'requires_approval' => is_array($item) && filter_var($item['requires_approval'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return $grants;
    }

    private function find(int $id): Role
    {
        return $this->writer->companyRoles()->find($id) ?? throw ApiException::notFound('Role not found');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
