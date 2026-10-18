<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for every business module (app/Modules/<Name>/<Name>ServiceProvider.php).
 *
 * It mounts the module's own migrations and route files. A module never edits a
 * shared routes file, so adding a module touches nothing outside its folder
 * except one line in bootstrap/providers.php.
 *
 * Route files, per portal (any may be absent):
 *   routes/admin.php          /api/v1/...                    signed-in admin user
 *   routes/vendor.php         /api/v1/vendors/...            signed-in vendor owner or staff
 *   routes/staff.php          /api/v1/vendors/staff/...      signed-in staff (staff cookie: portal/*, auth/*)
 *   routes/client.php         /api/v1/client/...             signed-in client
 *   routes/vendorclient.php   /api/v1/vendors/client/...     signed-in client (v1 vendor-client portal cookie)
 *   routes/<portal>.guest.php same prefix, NO login          (login, OTP, public reads)
 *   routes/public.php         /api/v1/public/...             public site, no login
 *
 * Authenticated files are protected by default; a route is only public if it is
 * deliberately put in a *.guest.php or public.php file.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** portal => [url prefix, middleware group for signed-in routes] */
    public const PORTALS = [
        'admin' => ['api/v1', 'portal.admin'],
        'vendor' => ['api/v1/vendors', 'portal.vendor'],
        'staff' => ['api/v1/vendors/staff', 'portal.staff'],
        'client' => ['api/v1/client', 'portal.client'],
        'vendorclient' => ['api/v1/vendors/client', 'portal.vendorclient'],
        'public' => ['api/v1/public', 'portal.guest'],
    ];

    public function boot(): void
    {
        $migrations = $this->modulePath('Database/Migrations');
        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }

        if (! $this->app->routesAreCached()) {
            $this->registerRoutes();
        }

        $this->bootModule();
    }

    /** Hook for policies, listeners, morph maps, observers. */
    protected function bootModule(): void {}

    protected function modulePath(string $relative = ''): string
    {
        $directory = dirname((string) (new ReflectionClass(static::class))->getFileName());

        return $relative === ''
            ? $directory
            : $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function registerRoutes(): void
    {
        // Guest routes first: a fixed public path ("settings/public") must win
        // over a signed-in wildcard of the same module ("settings/{key}").
        foreach (self::PORTALS as $portal => [$prefix, $group]) {
            $guest = $this->modulePath("routes/{$portal}.guest.php");
            if (is_file($guest)) {
                Route::prefix($prefix)->middleware(['api', 'portal.guest'])->group($guest);
            }

            $signedIn = $this->modulePath("routes/{$portal}.php");
            if (is_file($signedIn)) {
                Route::prefix($prefix)->middleware(['api', $group])->group($signedIn);
            }
        }
    }
}
