<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Identity\Actions\Permissions\PermissionCatalogueWriter;
use App\Modules\Identity\Http\Resources\V1\ModuleResource;
use App\Modules\Identity\Models\PermissionGroup;
use App\Modules\Identity\Queries\ModuleListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/modules — permission groups and their permissions. */
final class ModuleController
{
    public function __construct(private readonly PermissionCatalogueWriter $writer) {}

    public function index(Request $request, ModuleListQuery $list): JsonResponse
    {
        return ApiResponse::paginated($list->paginate($request), ModuleResource::class);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['module' => new ModuleResource($this->find($id)->load(['permissions' => static fn ($q) => $q->where('guard_name', 'admin')]))]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{name: string, slug?: string|null, description?: string|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::created(
            ['module' => new ModuleResource($this->writer->createGroup($input)->load(['permissions' => static fn ($q) => $q->where('guard_name', 'admin')]))],
            'Module created with permissions successfully',
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'in:0,1'],
        ]);

        return ApiResponse::success(
            ['module' => new ModuleResource($this->writer->updateGroup($this->find($id), $input)->load(['permissions' => static fn ($q) => $q->where('guard_name', 'admin')]))],
            'Module updated successfully',
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->writer->deleteGroup($this->find($id));

        return ApiResponse::success(null, 'Module deleted successfully');
    }

    public function addPermission(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $group = $this->find($id);
        $this->writer->addToGroup($group, (string) $input['name'], (string) $input['action'], $input['description'] ?? null);

        return ApiResponse::created(['module' => new ModuleResource($group->load(['permissions' => static fn ($q) => $q->where('guard_name', 'admin')]))], 'Custom permission added successfully');
    }

    private function find(int $id): PermissionGroup
    {
        return PermissionGroup::query()->find($id) ?? throw ApiException::notFound('Module not found');
    }
}
