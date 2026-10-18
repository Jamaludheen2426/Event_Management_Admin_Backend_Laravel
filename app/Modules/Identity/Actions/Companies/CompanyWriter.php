<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Companies;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Actions\Roles\ProvisionCompanyRoles;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

/**
 * Developer-only company administration (v1 /companies).
 *
 * Creating a company also creates its built-in roles and its first super
 * admin. v1 hashed that admin's password twice (bcrypt of a bcrypt), so the
 * new admin could never sign in; here the model's hashed cast hashes once.
 */
final class CompanyWriter
{
    private const FIELDS = ['name', 'slug', 'domain', 'logo', 'email', 'phone', 'address', 'timezone', 'max_users'];

    public function __construct(
        private readonly ProvisionCompanyRoles $roles,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, User $actor): Company
    {
        $this->assertFree('slug', (string) $input['slug'], 'Company slug already exists');
        if (! empty($input['email'])) {
            $this->assertFree('email', (string) $input['email'], 'Company email already exists');
        }
        $adminEmail = (string) $input['admin_email'];
        if (Tenancy::bypass(static fn (): bool => User::query()->where('email', $adminEmail)->exists())) {
            throw ApiException::conflict('Admin email already exists');
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            return DB::transaction(function () use ($input, $actor, $adminEmail, $registrar): Company {
                $company = Company::query()->create([
                    ...array_intersect_key($input, array_flip(self::FIELDS)),
                    'is_active' => (int) ($input['is_active'] ?? 1) === 1,
                    'created_by' => $actor->id,
                ]);

                $roles = $this->roles->handle($company->id);

                $admin = Tenancy::bypass(static fn (): User => User::query()->create([
                    'company_id' => $company->id,
                    'full_name' => $input['admin_full_name'],
                    'email' => $adminEmail,
                    'password' => $input['admin_password'],
                    'is_active' => true,
                    'login_access' => true,
                    'approval_status' => 'approved',
                    'created_by' => $actor->id,
                ]));

                $registrar->setPermissionsTeamId($company->id);
                $admin->syncRoles([$roles['super_admin']]);

                $this->activity->log('companies', 'create', "Created company: {$company->name}", $company, causer: $actor);

                return $company;
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }

    /** @param array<string, mixed> $input */
    public function update(Company $company, array $input, User $actor): Company
    {
        if (isset($input['slug']) && $input['slug'] !== $company->slug) {
            $this->assertFree('slug', (string) $input['slug'], 'Company slug already exists', $company->id);
        }
        if (! empty($input['email']) && $input['email'] !== $company->getAttribute('email')) {
            $this->assertFree('email', (string) $input['email'], 'Company email already exists', $company->id);
        }

        $changes = array_intersect_key($input, array_flip(self::FIELDS));
        if (array_key_exists('is_active', $input)) {
            $changes['is_active'] = (int) $input['is_active'] === 1;
        }

        $company->fill($changes + ['updated_by' => $actor->id])->save();
        $this->activity->log('companies', 'update', "Updated company: {$company->name}", $company, [], $input, $actor);

        return $company;
    }

    public function setStatus(Company $company, bool $active, User $actor): Company
    {
        if ($company->id === $actor->company_id && ! $active) {
            throw ApiException::badRequest('You cannot suspend your own company');
        }

        $was = $company->is_active;
        $company->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();

        // Suspending a company signs all of its users out at once.
        if ($was && ! $active) {
            $this->revokeCompanySessions($company);
        }

        $this->activity->log('companies', 'update_status', 'Changed company is_active from '.(int) $was.' to '.(int) $active, $company, causer: $actor);

        return $company;
    }

    public function delete(Company $company, User $actor): void
    {
        if ($company->id === (int) config('tenancy.house_company_id') || $company->id === $actor->company_id) {
            throw ApiException::badRequest('This company cannot be deleted');
        }

        DB::transaction(function () use ($company, $actor): void {
            $this->revokeCompanySessions($company);
            $company->delete();
            $this->activity->log('companies', 'delete', "Deleted company: {$company->name}", $company, causer: $actor);
        });
    }

    private function revokeCompanySessions(Company $company): void
    {
        $userIds = Tenancy::bypass(static fn () => User::query()->where('company_id', $company->id)->pluck('id'));

        PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }

    private function assertFree(string $column, string $value, string $message, ?int $exceptId = null): void
    {
        $taken = Company::query()->where($column, $value)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ApiException::conflict($message);
        }
    }
}
