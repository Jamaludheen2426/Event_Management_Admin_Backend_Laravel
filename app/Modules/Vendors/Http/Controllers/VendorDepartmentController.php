<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Http\Resources\RowResource;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Vendors\Actions\VendorDepartmentWriter;
use App\Modules\Vendors\Models\VendorDepartment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/vendors/departments (owner). */
final class VendorDepartmentController
{
    public function __construct(private readonly VendorDepartmentWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $page = (new CallbackListQuery(VendorDepartment::query(), ['name', 'description'], ['created_at', 'name']))
            ->paginate($request)
            ->through(static fn (VendorDepartment $d): RowResource => new RowResource($d));

        return ApiResponse::paginated($page);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['department' => new RowResource($this->writer->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::created(['department' => new RowResource($this->writer->create($request->all()))], 'Department created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(['department' => new RowResource($this->writer->update($this->writer->find($id), $request->all()))], 'Department updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->writer->delete($this->writer->find($id));

        return ApiResponse::success(null, 'Department deleted successfully');
    }
}
