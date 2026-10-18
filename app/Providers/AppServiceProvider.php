<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Application-level wiring that belongs to no module. The shared kernel lives in
 * App\Core\CoreServiceProvider; business modules have their own providers.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
