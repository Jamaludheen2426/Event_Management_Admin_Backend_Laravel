<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Identity\Http\Resources\V1\ModuleResource;
use App\Modules\Identity\Http\Resources\V1\PermissionResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\PermissionGroup;
use App\Modules\Identity\Models\Role;
use App\Modules\Vendors\Actions\VendorRoleWriter;
use App\Modules\Vendors\Http\Resources\V1\VendorRoleResource;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A vendor's roles and the permission catalogue it grants from, for the owner
 * (/vendors/roles, /vendors/modules, /vendors/permissions) and for staff
 * (/vendors/staff/portal/...). The two portals answered lists differently in
 * v1 (staff got the page nested inside data); both are kept.
 */
final class VendorRoleController
{
    public function __construct(private readonly VendorRoleWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $page = (new CallbackListQuery(
            $this->writer->roles()->with('permissions'),
            ['label', 'name'],
            ['created_at'],
        ))->paginate($request)->through(static fn (Role $r): VendorRoleResource => new VendorRoleResource($r));

        return $this->actor($request)->is_owner
            ? ApiResponse::paginated($page)
            : ApiResponse::nestedPage($page, 'Roles retrieved successfully');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(['role' => $this->present($this->writer->find($id))], $this->actor($request)->is_owner ? 'Success' : 'Role retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::created(['role' => $this->present($this->writer->create($request->all(), $this->actor($request)))], 'Role created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = $this->writer->update($this->writer->find($id), $request->all(), $this->actor($request));

        return ApiResponse::success(['role' => $this->present($role)], 'Role updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->writer->delete($this->writer->find($id), $this->actor($request));

        return ApiResponse::success(null, 'Role deleted successfully');
    }

    public function assignPermissions(Request $request, int $id): JsonResponse
    {
        $role = $this->writer->assignPermissions($this->writer->find($id), $request->input('permissions', []), $this->actor($request));

        return ApiResponse::success(['role' => $this->present($role)], 'Permissions assigned successfully');
    }

    /** The vendor permission catalogue grouped by module. */
    public function modules(): JsonResponse
    {
        $vendorOnly = static fn (Builder|HasMany $q) => $q->where('guard_name', 'vendor')->where('is_active', true);
        $modules = PermissionGroup::query()
            ->where('is_active', true)
            ->whereHas('permissions', $vendorOnly)
            ->with(['permissions' => $vendorOnly])
            ->orderBy('name')
            ->get();

        return ApiResponse::success(['modules' => ModuleResource::collection($modules)]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::query()->where('guard_name', 'vendor')->where('is_active', true)
            ->with('group')->get()
            ->sortBy(static fn (Permission $p): string => ($p->group->slug ?? '').'|'.$p->label)
            ->values();

        return ApiResponse::success(['permissions' => $permissions->map(static fn (Permission $p): array => [
            ...(new PermissionResource($p))->toArray($request),
            'moduleRef' => $p->group?->only(['id', 'name', 'slug']),
        ])->all()]);
    }

    private function present(Role $role): VendorRoleResource
    {
        return new VendorRoleResource($role->load('permissions'));
    }

    private function actor(Request $request): VendorUser
    {
        /** @var VendorUser */
        return $request->user();
    }
}
