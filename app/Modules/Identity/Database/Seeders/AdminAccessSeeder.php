<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Seeders;

use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Actions\Roles\ProvisionCompanyRoles;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The house company's built-in roles, plus — on a local or fresh install only —
 * one developer login taken from SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD.
 * Production gets its users from `legacy:import`, never from here.
 */
final class AdminAccessSeeder extends Seeder
{
    public function run(ProvisionCompanyRoles $provision): void
    {
        $companyId = (int) config('tenancy.house_company_id');
        $roles = $provision->handle($companyId);

        $email = (string) config('auth.seed_admin.email');
        $password = (string) config('auth.seed_admin.password');
        if ($email === '' || $password === '' || app()->isProduction()) {
            return;
        }

        Tenancy::bypass(static function () use ($companyId, $email, $password, $roles): void {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'company_id' => $companyId,
                'full_name' => 'Developer',
                'password' => $password,
                'is_active' => true,
                'login_access' => true,
                'approval_status' => 'approved',
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
            $user->syncRoles([$roles['developer']]);
        });
    }
}
