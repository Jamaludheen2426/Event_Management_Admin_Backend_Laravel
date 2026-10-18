<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Closure;

/**
 * How many things use a plugin, shown on the plugin cards ("used by 4
 * vendors"). Modules register counters for the plugins they consume; the
 * Vendors module counts website-management. Unregistered plugins show 0.
 */
final class PluginUsage
{
    /** @var array<string, Closure(int): int> slug => fn(companyId) */
    private array $counters = [];

    /** @param Closure(int): int $counter */
    public function register(string $slug, Closure $counter): void
    {
        $this->counters[$slug] = $counter;
    }

    public function count(string $slug, int $companyId): int
    {
        return isset($this->counters[$slug]) ? ($this->counters[$slug])($companyId) : 0;
    }
}
