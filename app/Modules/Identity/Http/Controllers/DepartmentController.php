<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Identity\Actions\Departments\DepartmentWriter;
use App\Modules\Identity\Http\Resources\V1\DepartmentResource;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Queries\DepartmentListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/departments */
final class DepartmentController
{
    public function __construct(private readonly DepartmentWriter $writer) {}

    public function index(Request $request, DepartmentListQuery $list): JsonResponse
    {
        return ApiResponse::paginated($list->paginate($request), DepartmentResource::class);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['department' => new DepartmentResource($this->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{name: string, description?: string|null, is_active?: int|bool|string|null} $input */
        $input = $request->validate($this->rules(true));

        return ApiResponse::created(['department' => new DepartmentResource($this->writer->create($input))], 'Department created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $department = $this->writer->update($this->find($id), $request->validate($this->rules(false)));

        return ApiResponse::success(['department' => new DepartmentResource($department)], 'Department updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->writer->delete($this->find($id));

        return ApiResponse::success(null, 'Department deleted successfully');
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'in:0,1,true,false'],
        ];
    }

    private function find(int $id): Department
    {
        return Department::query()->find($id) ?? throw ApiException::notFound('Department not found');
    }
}
