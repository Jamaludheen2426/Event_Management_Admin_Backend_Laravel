<?php

declare(strict_types=1);

namespace App\Modules\Clients;

use App\Core\Approvals\ApprovalRegistry;
use App\Core\Support\ModuleServiceProvider;
use App\Modules\Clients\Actions\ClientApprovals;
use App\Modules\Clients\Actions\ClientSettings;
use App\Modules\Clients\Actions\SocialSignIn;
use App\Modules\Clients\Http\Middleware\EnsureClientUsable;
use App\Modules\Clients\Support\MobileSignInHooks;
use Illuminate\Routing\Router;

/**
 * Clients: every vendor's customers (v1 website_clients + vendor_clients), all
 * their ways of signing in (password, phone code, Google/Facebook, 2FA, the
 * vendor-site handoff), their sessions, devices and settings, and the
 * vendor's newsletter list.
 */
final class ClientsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Extension points later modules register into at boot.
        $this->app->singleton(MobileSignInHooks::class);
        $this->app->singleton(SocialSignIn::class);
        $this->app->singleton(ClientSettings::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('account.client', EnsureClientUsable::class);

        $this->app->make(ApprovalRegistry::class)->register('website_clients', 'website_client', ClientApprovals::class);
    }
}
