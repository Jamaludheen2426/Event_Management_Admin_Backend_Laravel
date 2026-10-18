<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Vendors\Actions\VendorWriter;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Http\Resources\V1\VendorResource;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/vendors (admin panel). The list answered { data: { data, pagination } }
 * in v1 and the screen reads that shape; detail answered the vendor itself as data.
 */
final class AdminVendorController
{
    public function __construct(
        private readonly VendorWriter $writer,
        private readonly Approvals $approvals,
        private readonly SessionTokens $tokens,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $page = (new CallbackListQuery(
            Vendor::query()->with(['owner', 'planType']),
            [],
            ['created_at', 'company_name', 'status'],
            false,
            static function (Builder $query) use ($search, $request): void {
                // v1 searched name, email, company_name, contact — the owner's
                // person and login now live on the owner row.
                if ($search !== '') {
                    $query->where(static fn (Builder $w) => $w->where('company_name', 'like', "%{$search}%")
                        ->orWhereHas('owner', static fn (Builder $o) => $o->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%")));
                }
                $status = $request->query('status');
                if (in_array($status, ['active', 'inactive'], true)) {
                    $query->where('status', $status);
                }
            },
        ))->paginate($request)->through(static fn (Vendor $v): VendorResource => new VendorResource($v));

        return ApiResponse::nestedPage($page, 'Vendors retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->find($id)), 'Vendor retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all();
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'vendors', 'create', 'vendor', null, $input,
            fn (): JsonResponse => ApiResponse::created($this->present($this->writer->create($input, $actor)), 'Vendor created successfully'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vendor = $this->find($id);
        $input = $request->all();
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'vendors', 'update', 'vendor', $vendor->id, $input,
            fn (): JsonResponse => ApiResponse::success($this->present($this->writer->update($vendor, $input, $actor)), 'Vendor updated successfully'));
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $vendor = $this->writer->setStatus($this->find($id), $request->input('status'), $this->actor($request));

        return ApiResponse::success($this->present($vendor), 'Vendor status updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $vendor = $this->find($id);
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, 'vendors', 'delete', 'vendor', $vendor->id, [],
            function () use ($vendor, $actor): JsonResponse {
                $this->writer->delete($vendor, $actor);

                return ApiResponse::success(null, 'Vendor deleted successfully');
            });
    }

    /** Open the vendor portal as this vendor: sets the vendor cookies for the admin's browser. */
    public function impersonate(Request $request, int $id): JsonResponse
    {
        $vendor = $this->find($id);
        if ($vendor->status !== VendorStatus::Active) {
            throw ApiException::forbidden('Vendor account is inactive.');
        }
        $owner = $vendor->owner ?? throw ApiException::notFound('Vendor not found');
        $issued = $this->tokens->issue($owner);

        $response = ApiResponse::success(['vendor_id' => $vendor->id, 'vendor' => $this->present($vendor)], 'Impersonation session created');

        return $this->tokens->attach($response, VendorAuthController::ACCESS_COOKIE, $issued['access'], VendorAuthController::REFRESH_COOKIE, $issued['refresh']);
    }

    private function find(int $id): Vendor
    {
        return Vendor::query()->find($id) ?? throw ApiException::notFound('Vendor not found');
    }

    private function present(Vendor $vendor): VendorResource
    {
        return new VendorResource($vendor->load(['owner', 'planType']));
    }

    private function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
