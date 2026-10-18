<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Vendors\Actions\StaffWriter;
use App\Modules\Vendors\Http\Resources\V1\StaffResource;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff management, for the owner (/vendors/staff) and for staff with
 * staff.* permissions (/vendors/staff/portal/staff). v1 answered the two
 * portals with slightly different messages and shapes; both are kept.
 */
final class StaffController
{
    public function __construct(private readonly StaffWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $designation = $request->query('designation');
        $workStatus = $request->query('work_status');

        $page = (new CallbackListQuery(
            VendorUser::query()->where('is_owner', false)->with(['roles', 'department']),
            ['name', 'email', 'mobile', 'emp_id', 'designation'],
            ['created_at', 'name', 'is_active', 'designation', 'doj'],
            false,
            static function (Builder $query) use ($designation, $workStatus): void {
                if (is_string($designation) && $designation !== '') {
                    $query->where('designation', $designation);
                }
                if (is_string($workStatus) && $workStatus !== '') {
                    $query->where('work_status', $workStatus);
                }
            },
        ))->paginate($request)->through(static fn (VendorUser $s): StaffResource => new StaffResource($s));

        return ApiResponse::nestedPage($page, 'Staff retrieved successfully');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $staff = new StaffResource($this->writer->find($id)->load(['roles.permissions', 'department']));

        return $this->owner($request)
            ? ApiResponse::success($staff, 'Staff member retrieved successfully')
            : ApiResponse::success(['staff' => $staff], 'Staff retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $staff = $this->writer->create($request->all(), $this->actor($request));

        return ApiResponse::created(
            new StaffResource($staff->load(['roles', 'department'])),
            $this->owner($request) ? 'Staff member created successfully' : 'Staff created successfully',
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $staff = $this->writer->update($this->writer->find($id), $request->all(), $this->actor($request));

        return ApiResponse::success(
            new StaffResource($staff->load(['roles', 'department'])),
            $this->owner($request) ? 'Staff member updated successfully' : 'Staff updated successfully',
        );
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $staff = $this->writer->setActive($this->writer->find($id), $request->input('is_active'), $this->actor($request));

        return ApiResponse::success(new StaffResource($staff->load('roles')), 'Staff status updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->writer->delete($this->writer->find($id), $this->actor($request));

        return ApiResponse::success(null, $this->owner($request) ? 'Staff member deleted successfully' : 'Staff deleted successfully');
    }

    /** Owner only: PUT /vendors/staff/:id/role { role_id }. */
    public function assignRole(Request $request, int $id): JsonResponse
    {
        $staff = $this->writer->assignRole($this->writer->find($id), $request->input('role_id'));

        return ApiResponse::success(['staff' => new StaffResource($staff->load('roles'))], 'Staff role updated successfully');
    }

    private function owner(Request $request): bool
    {
        return $this->actor($request)->is_owner;
    }

    private function actor(Request $request): VendorUser
    {
        /** @var VendorUser */
        return $request->user();
    }
}
