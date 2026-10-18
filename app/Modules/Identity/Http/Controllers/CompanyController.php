<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Actions\Companies\CompanyWriter;
use App\Modules\Identity\Http\Resources\V1\CompanyResource;
use App\Modules\Identity\Http\Resources\V1\RoleResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Queries\CompanyDetailQuery;
use App\Modules\Identity\Queries\CompanyListQuery;
use App\Modules\Identity\Support\AdminPassword;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/v1/companies — developer only. */
final class CompanyController
{
    public function __construct(
        private readonly CompanyWriter $writer,
        private readonly CompanyDetailQuery $detail,
    ) {}

    public function index(Request $request, CompanyListQuery $list): JsonResponse
    {
        return ApiResponse::paginated($list->paginate($request), CompanyResource::class);
    }

    public function dashboard(): JsonResponse
    {
        $companies = $this->detail->withUserCounts(Company::query())->get();
        [$total, $active] = Tenancy::bypass(static fn (): array => [
            User::query()->count(),
            User::query()->where('is_active', true)->where('approval_status', 'approved')->count(),
        ]);

        return ApiResponse::success([
            'stats' => [
                'total_companies' => $companies->count(),
                'active_companies' => $companies->where('is_active', true)->count(),
                'suspended_companies' => $companies->where('is_active', false)->count(),
                'total_users' => $total,
                'active_users' => $active,
            ],
            'companies' => CompanyResource::collection($companies),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $company = $this->find($id);

        $users = $this->detail->usersWithRoles($company)->map(static function (array $row) use ($request): array {
            /** @var User $user */
            $user = $row['user'];
            /** @var Role|null $role */
            $role = $row['role'];

            return [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'is_active' => $user->approval_status === 'pending' ? 2 : (int) $user->is_active,
                'role_id' => $role?->id,
                'role' => $role === null ? null : (new RoleResource($role))->toArray($request),
            ];
        })->values();

        $level = static fn (array $u): int => (int) ($u['role']['level'] ?? 0);

        return ApiResponse::success(['company' => [
            ...(new CompanyResource($company))->toArray($request),
            'users' => $users,
            'stats' => [
                'total_users' => $users->count(),
                'active_users' => $users->where('is_active', 1)->count(),
                'super_admins' => $users->filter(static fn (array $u): bool => $level($u) >= 100)->count(),
                'admins' => $users->filter(static fn (array $u): bool => $level($u) >= 50 && $level($u) < 100)->count(),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $missingAdmin = 'Admin details are required (admin_full_name, admin_email, admin_password)';
        $input = $request->validate([
            ...$this->rules(true),
            'admin_full_name' => ['required', 'string', 'max:200'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => AdminPassword::rules(),
        ], [
            'admin_full_name.required' => $missingAdmin,
            'admin_email.required' => $missingAdmin,
            'admin_password.required' => $missingAdmin,
            ...AdminPassword::messages('admin_password'),
        ]);

        return ApiResponse::created(['company' => new CompanyResource($this->writer->create($input, $this->actor($request)))], 'Company created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = $this->writer->update($this->find($id), $request->validate($this->rules(false)), $this->actor($request));

        return ApiResponse::success(['company' => new CompanyResource($company)], 'Company updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->writer->delete($this->find($id), $this->actor($request));

        return ApiResponse::success(null, 'Company deleted successfully');
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $value = (string) $request->input('is_active');
        if (! in_array($value, ['0', '1', 'true', 'false'], true)) {
            throw ApiException::badRequest('is_active must be 0 or 1');
        }

        $company = $this->writer->setStatus($this->find($id), in_array($value, ['1', 'true'], true), $this->actor($request));

        return ApiResponse::success(['company' => new CompanyResource($company)], 'Company status updated successfully');
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:200'],
            'slug' => [$required, 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:500'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'in:0,1'],
        ];
    }

    private function find(int $id): Company
    {
        return Company::query()->find($id) ?? throw ApiException::notFound('Company not found');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
