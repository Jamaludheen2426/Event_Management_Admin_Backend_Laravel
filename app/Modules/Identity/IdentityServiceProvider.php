<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Approvals\ApprovalPolicy;
use App\Core\Approvals\ApprovalRegistry;
use App\Core\Support\ModuleServiceProvider;
use App\Modules\Identity\Actions\Roles\RoleWriter;
use App\Modules\Identity\Actions\Users\UserWriter;
use App\Modules\Identity\Approvals\RoleApprovalPolicy;
use App\Modules\Identity\Http\Middleware\EnsureAdminAccountUsable;
use App\Modules\Identity\Http\Middleware\RequireDeveloper;
use App\Modules\Identity\Http\Middleware\RequireMinLevel;
use App\Modules\Identity\Http\Middleware\RequirePermission;
use App\Modules\Identity\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;

/**
 * Identity: admin users, departments, roles and permissions, companies,
 * approvals, the activity log, admin authentication.
 */
final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApprovalPolicy::class, RoleApprovalPolicy::class);
    }

    protected function bootModule(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('account.admin', EnsureAdminAccountUsable::class);
        $router->aliasMiddleware('perm', RequirePermission::class);
        $router->aliasMiddleware('level', RequireMinLevel::class);
        $router->aliasMiddleware('developer', RequireDeveloper::class);

        // Developer and super admin pass every admin permission check (v1 rule).
        Gate::before(static fn (mixed $user): ?bool => $user instanceof User && $user->bypassesPermissions() ? true : null);

        $registry = $this->app->make(ApprovalRegistry::class);
        $registry->register('employees', 'employee', UserWriter::class);
        $registry->register('roles', 'role', RoleWriter::class);
    }
}
