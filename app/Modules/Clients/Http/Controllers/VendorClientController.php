<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Clients\Actions\ClientWriter;
use App\Modules\Clients\Http\Resources\V1\VendorClientResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A vendor's clients, for the owner (/vendors/clients) and for staff with
 * client.* permissions (/vendors/staff/portal/clients). The vendor scope keeps
 * every query inside the signed-in vendor.
 */
final class VendorClientController
{
    public function __construct(private readonly ClientWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('is_active', $request->query('status'));
        $plan = $request->query('plan');
        $type = $request->query('registration_type');
        $search = trim((string) $request->query('search', ''));

        // v1: an all-digit search also matches the id exactly.
        $request = $request->duplicate(array_diff_key($request->query(), ['search' => 1, 'is_active' => 1, 'status' => 1]));
        $page = (new CallbackListQuery(
            Client::query()->with('planType'),
            [],
            ['created_at', 'name', 'is_active'],
            false,
            static function (Builder $query) use ($status, $plan, $type, $search): void {
                if (is_numeric($status)) {
                    $query->where('is_active', (int) $status);
                }
                if (is_string($plan) && $plan !== '') {
                    mb_strtolower($plan) === 'guest'
                        ? $query->where('registration_type', 'guest')
                        : $query->whereHas('planType', static fn ($q) => $q->withoutGlobalScopes()->where('name', $plan));
                }
                if (in_array(is_string($type) ? mb_strtolower($type) : null, ['guest', 'client'], true)) {
                    $query->where('registration_type', mb_strtolower((string) $type));
                }
                if ($search !== '') {
                    $query->where(static function (Builder $q) use ($search): void {
                        if (ctype_digit($search)) {
                            $q->orWhere('id', (int) $search);
                        }
                        foreach (['name', 'email', 'mobile', 'code', 'city'] as $column) {
                            $q->orWhere($column, 'like', '%'.$search.'%');
                        }
                    });
                }
            },
        ))->paginate($request)->through(static fn (Client $c): VendorClientResource => new VendorClientResource($c));

        return ApiResponse::nestedPage($page, 'Clients retrieved successfully');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $client = new VendorClientResource($this->writer->find($id));

        return ApiResponse::success($this->owner($request) ? $client : ['client' => $client], 'Client retrieved successfully');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success(new VendorClientResource($this->writer->vendorCreate($request->all())), 'Client created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->writer->vendorUpdate($this->writer->find($id), $request->all());

        return ApiResponse::success(new VendorClientResource($client), 'Client updated successfully');
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $client = $this->writer->setActive($this->writer->find($id), $request->input('is_active'));

        return ApiResponse::success(new VendorClientResource($client), 'Client status updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->writer->delete($this->writer->find($id));

        return ApiResponse::success(null, 'Client deleted successfully');
    }

    private function owner(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof VendorUser && $user->is_owner;
    }
}
