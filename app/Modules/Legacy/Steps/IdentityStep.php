<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Identity\Actions\Roles\ProvisionCompanyRoles;
use App\Modules\Identity\Models\User;
use App\Modules\Legacy\Support\Import;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Support\Facades\DB;

/**
 * Admin users, departments, roles and their permission grants.
 *
 * Roles: v1 admin roles become the company's roles of the same slug (the
 * built-in developer / super_admin / admin are matched, not duplicated);
 * v1 vendor roles become vendor-guard roles of their vendor (attached to
 * staff in the Vendors step). Grants are matched to the new permission
 * catalogues by slug; slugs the new API no longer checks are reported.
 */
final class IdentityStep implements Step
{
    /** v1 admin permission slugs the new catalogue names differently. */
    private const RENAMED = [
        'admin_roles.view' => ['roles.view'],
        'admin_modules.view' => ['modules.view'],
        'admin_settings.view' => ['settings.view'],
        'admin_settings.edit' => ['settings.edit'],
        'locations.manage' => ['locations.create', 'locations.edit', 'locations.delete'],
    ];

    public function __construct(private readonly ProvisionCompanyRoles $provision) {}

    public function name(): string
    {
        return 'identity';
    }

    public function run(Import $import): void
    {
        $this->departments($import);
        $this->users($import);
        $this->roles($import);
    }

    private function departments(Import $i): void
    {
        $rows = $i->rows('departments');
        $i->put('departments', 'departments', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, 'name' => $r['name'], 'description' => $r['description'],
            'is_active' => Import::bit($r['is_active']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
            'deleted_at' => Import::at($r['deleted_at']),
        ], $rows), count($rows));
    }

    private function users(Import $i): void
    {
        $known = static fn (string $table): array => DB::table($table)->pluck('id')->flip()->all();
        [$countries, $states, $districts, $cities, $departments] = [$known('countries'), $known('states'), $known('districts'), $known('cities'), $known('departments')];
        $ref = static fn (array $set, mixed $id): ?int => is_numeric($id) && isset($set[(int) $id]) ? (int) $id : null;

        $rows = $i->rows('users');
        $i->put('users', 'users', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, 'department_id' => $ref($departments, $r['department_id']),
            'full_name' => $r['full_name'], 'email' => mb_strtolower(trim((string) $r['email'])), 'username' => $r['username'], 'phone' => $r['phone'],
            'avatar' => $r['avatar'], 'password' => $r['password'], 'dob' => Import::at($r['dob']), 'gender' => $r['gender'],
            'marital_status' => $r['marital_status'], 'designation' => $r['designation'], 'doj' => Import::at($r['doj']), 'dor' => Import::at($r['dor']),
            'address' => $r['address'], 'country_id' => $ref($countries, $r['country_id']), 'state_id' => $ref($states, $r['state_id']),
            // v1 names: users.city_id was the district, pincode_id the city.
            'district_id' => $ref($districts, $r['city_id']), 'city_id' => $ref($cities, $r['pincode_id']),
            'timezone' => $r['timezone'], 'login_access' => Import::bit($r['login_access']), ...Import::approval($r['is_active']),
            'email_verified_at' => Import::at($r['email_verified_at']), 'last_login_at' => Import::at($r['last_login_at']),
            'password_changed_at' => Import::at($r['password_changed_at']), 'remember_token' => null, 'created_by' => null, 'updated_by' => null,
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], $rows), count($rows));
        foreach ($rows as $r) {
            $i->map('user_role', (int) $r['id'], (int) $r['role_id']);
        }
    }

    private function roles(Import $i): void
    {
        $roles = $i->rows('roles', withDeleted: false);
        $companies = array_unique([1, ...array_filter(array_map(static fn (array $r): ?int => $r['vendor_id'] === null ? ($r['company_id'] ?? 1) : null, $roles))]);
        foreach ($companies as $company) {
            $this->provision->handle((int) $company);
        }

        $written = 0;
        foreach ($roles as $r) {
            $vendorRole = $r['vendor_id'] !== null;
            $key = ['team_id' => $vendorRole ? (int) $r['vendor_id'] : ($r['company_id'] ?? 1), 'name' => (string) $r['slug'], 'guard_name' => $vendorRole ? 'vendor' : 'admin'];
            if ($vendorRole && ! DB::table('vendors')->where('id', $key['team_id'])->exists()) {
                $i->note("role {$r['id']} ({$r['slug']}): its vendor {$r['vendor_id']} does not exist, skipped");

                continue;
            }
            $existing = DB::table('roles')->where($key)->value('id');
            if ($existing === null) {
                $existing = DB::table('roles')->insertGetId([...$key,
                    'label' => $r['name'], 'description' => $r['description'], 'level' => $vendorRole ? 10 : (int) $r['level'],
                    'is_default' => Import::bit($r['is_default'], 0), 'is_active' => Import::bit($r['is_active']), 'approval_status' => 'approved',
                    'approved_at' => Import::at($r['approved_at']), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
                ]);
            }
            $i->map($vendorRole ? 'vendor_role' : 'admin_role', (int) $r['id'], (int) $existing);
            $written++;
        }
        $i->count('roles', 'roles', count($roles), $written);

        // Permission grants, by slug, into the catalogue of the role's guard.
        $slugs = collect($i->rows('permissions', withDeleted: false))->pluck('slug', 'id');
        $catalogue = DB::table('permissions')->get(['id', 'name', 'guard_name'])->groupBy('guard_name')
            ->map(static fn ($perms) => $perms->pluck('id', 'name'));
        $grants = $i->rows('role_permissions', withDeleted: false);
        $links = [];
        $unknown = [];
        foreach ($grants as $g) {
            $vendorRole = $i->id('vendor_role', $g['role_id']);
            $role = $vendorRole ?? $i->id('admin_role', $g['role_id']);
            $slug = (string) ($slugs[$g['permission_id']] ?? '');
            $guard = $vendorRole !== null ? 'vendor' : 'admin';
            $permissions = array_values(array_filter(array_map(
                static fn (string $s): ?int => $catalogue[$guard][$s] ?? null,
                $guard === 'admin' ? (self::RENAMED[$slug] ?? [$slug]) : [$slug],
            )));
            if ($role === null || $permissions === []) {
                $unknown["{$guard} role {$g['role_id']}"][] = $slug;

                continue;
            }
            foreach ($permissions as $permission) {
                $links["{$permission}|{$role}"] = ['permission_id' => $permission, 'role_id' => $role, 'requires_approval' => Import::bit($g['requires_approval'], 0)];
            }
        }
        $i->link('role_permissions', 'role_has_permissions', array_values($links), count($grants));
        foreach ($unknown as $role => $slugs) {
            $i->note("{$role}: ".count($slugs).' grants of permissions that do not exist for this kind of role, left out ('.implode(', ', array_slice(array_filter($slugs), 0, 12)).(count($slugs) > 12 ? ', …' : '').')');
        }

        // Each admin user keeps their role.
        $userType = (new User)->getMorphClass();
        $users = DB::table('users')->get(['id', 'company_id']);
        $assign = [];
        foreach ($users as $user) {
            $role = $i->id('admin_role', $i->id('user_role', $user->id));
            if ($role !== null) {
                $assign[] = ['role_id' => $role, 'model_type' => $userType, 'model_id' => $user->id, 'team_id' => $user->company_id];
            } else {
                $i->note("user {$user->id}: no role to give");
            }
        }
        $i->link('users.role_id', 'model_has_roles', $assign, $users->count());

        // Vendor staff keep their vendor role (the Vendors step recorded who had which).
        $staffType = (new VendorUser)->getMorphClass();
        $assign = [];
        $staff = $i->mapOf('vendor_staff');
        foreach ($staff as $old => $vendorUserId) {
            $role = $i->id('vendor_role', $i->id('staff_role', $old));
            $vendorId = DB::table('vendor_users')->where('id', $vendorUserId)->value('vendor_id');
            if ($role !== null && $vendorId !== null) {
                $assign[] = ['role_id' => $role, 'model_type' => $staffType, 'model_id' => $vendorUserId, 'team_id' => $vendorId];
            }
        }
        $i->link('vendor_staff.role_id', 'model_has_roles', $assign, count($staff));
    }
}
