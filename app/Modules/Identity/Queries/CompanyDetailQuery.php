<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Developer screens: a company's users with their roles, and per-company user counts. */
final class CompanyDetailQuery
{
    /** @return Collection<int, array{user: User, role: Role|null}> three queries, whatever the size */
    public function usersWithRoles(Company $company): Collection
    {
        $users = Tenancy::bypass(static fn () => User::query()->where('company_id', $company->id)->get());
        $roles = Role::query()->where('guard_name', 'admin')->where('team_id', $company->id)->get()->keyBy('id');
        $assigned = DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn('model_id', $users->pluck('id'))
            ->pluck('role_id', 'model_id');

        return $users->map(static fn (User $user): array => [
            'user' => $user,
            'role' => $roles->get($assigned->get($user->id)),
        ]);
    }

    /**
     * Adds user_count and active_user_count columns.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function withUserCounts(Builder $query): Builder
    {
        return $query->select('companies.*')
            ->selectSub('select count(*) from users where users.company_id = companies.id and users.deleted_at is null', 'user_count')
            ->selectSub("select count(*) from users where users.company_id = companies.id and users.is_active = 1 and users.approval_status = 'approved' and users.deleted_at is null", 'active_user_count');
    }
}
