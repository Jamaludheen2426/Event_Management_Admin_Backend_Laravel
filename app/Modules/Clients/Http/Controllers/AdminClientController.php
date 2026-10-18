<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Clients\Actions\ClientWriter;
use App\Modules\Clients\Http\Resources\V1\ClientResource;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Support\ClientPassword;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * /api/v1/website-clients — the admin's client list. With the client systems
 * merged it shows the clients of every vendor of the company (v1: only
 * website_clients); ?vendor_id= and ?source= narrow it.
 */
final class AdminClientController
{
    public function __construct(
        private readonly ClientWriter $writer,
        private readonly Approvals $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $source = $request->query('source');
        $vendor = $request->query('vendor_id');

        $page = (new CallbackListQuery(
            $this->writer->adminQuery()->with(['vendor', 'favourites']),
            ['name', 'email', 'mobile'],
            ['created_at', 'name', 'email', 'last_login_at'],
            false,
            static function (Builder $query) use ($source, $vendor): void {
                if (is_string($source) && $source !== '' && $source !== 'all') {
                    $query->where('source', $source);
                }
                if (is_numeric($vendor)) {
                    $query->where('vendor_id', (int) $vendor);
                }
            },
        ))->paginate($request);

        return ApiResponse::paginated($page, ClientResource::class);
    }

    public function stats(): JsonResponse
    {
        $counts = $this->writer->adminQuery()->toBase()->selectRaw('is_active, count(*) as total')->groupBy('is_active')->pluck('total', 'is_active');
        $cannotSignIn = $this->writer->adminQuery()->whereNull('password')->whereIn('source', ['website', 'admin'])->count();

        return ApiResponse::success(['stats' => [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[1] ?? 0),
            'inactive' => (int) ($counts[0] ?? 0),
            'blocked' => (int) ($counts[2] ?? 0),
            'cannot_sign_in' => $cannotSignIn,
        ]]);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['client' => $this->present($this->writer->adminFind($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $input = $this->sealPassword($request->all());

        return $this->approvals->guard($admin, 'website_clients', 'create', 'website_client', null, $input,
            fn (): JsonResponse => ApiResponse::created(['client' => $this->present($this->writer->adminCreate($input, $admin->id))], 'Client created successfully'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->writer->adminFind($id);
        $input = $this->sealPassword($request->all());

        return $this->approvals->guard($this->admin($request), 'website_clients', 'update', 'website_client', $client->id, $input,
            fn (): JsonResponse => ApiResponse::success(['client' => $this->present($this->writer->adminUpdate($client, $input))], 'Client updated successfully'));
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $client = $this->writer->setActive($this->writer->adminFind($id), $request->input('is_active'));

        return ApiResponse::success(['client' => $this->present($client)], 'Client status updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $client = $this->writer->adminFind($id);

        return $this->approvals->guard($this->admin($request), 'website_clients', 'delete', 'website_client', $client->id, [],
            function () use ($client): JsonResponse {
                $this->writer->delete($client);

                return ApiResponse::success(null, 'Client deleted successfully');
            });
    }

    /**
     * A new password is checked and hashed before anything else sees it, so
     * an approval request never stores it in plain text.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function sealPassword(array $input): array
    {
        if (($input['password'] ?? null) !== null && $input['password'] !== '') {
            $input['password'] = Hash::make(ClientPassword::website($input['password']));
        }

        return $input;
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function present(Client $client): ClientResource
    {
        return new ClientResource($client->loadMissing(['vendor', 'favourites']));
    }
}
