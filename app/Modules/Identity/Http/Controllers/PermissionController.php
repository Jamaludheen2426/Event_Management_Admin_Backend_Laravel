<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Identity\Actions\Permissions\PermissionCatalogueWriter;
use App\Modules\Identity\Http\Resources\V1\PermissionResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Queries\PermissionListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/permissions — the admin permission catalogue. */
final class PermissionController
{
    public function __construct(private readonly PermissionCatalogueWriter $writer) {}

    public function index(Request $request, PermissionListQuery $list): JsonResponse
    {
        return ApiResponse::paginated($list->paginate($request), PermissionResource::class);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['permission' => new PermissionResource($this->find($id)->load('group'))]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{name: string, slug?: string|null, module: string, description?: string|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+\.[a-z0-9_.]+$/'],
            'module' => ['required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::created(
            ['permission' => new PermissionResource($this->writer->createPermission($input)->load('group'))],
            'Permission created successfully',
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_]+\.[a-z0-9_.]+$/'],
            'module' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'in:0,1'],
        ]);

        return ApiResponse::success(
            ['permission' => new PermissionResource($this->writer->updatePermission($this->find($id), $input)->load('group'))],
            'Permission updated successfully',
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->writer->deletePermission($this->find($id));

        return ApiResponse::success(null, 'Permission deleted successfully');
    }

    private function find(int $id): Permission
    {
        return Permission::query()->where('guard_name', 'admin')->find($id) ?? throw ApiException::notFound('Permission not found');
    }
}
