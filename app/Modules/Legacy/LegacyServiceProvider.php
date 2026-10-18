<?php

declare(strict_types=1);

namespace App\Modules\Legacy;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Legacy\Console\ImportLegacy;

/**
 * The one-time import from the Node API's database. Depends on every other
 * module; none depends on it.
 */
final class LegacyServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportLegacy::class]);
        }
    }
}
