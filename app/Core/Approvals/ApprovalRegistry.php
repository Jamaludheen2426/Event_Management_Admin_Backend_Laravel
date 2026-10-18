<?php

declare(strict_types=1);

namespace App\Core\Approvals;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Which handler changes which approvable resource. Filled by module providers.
 *
 * Keyed by module slug + resource type, because one module can own several
 * resources (locations: country, state, district, city).
 */
final class ApprovalRegistry
{
    /** @var array<string, class-string<ApprovalHandler>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<ApprovalHandler> $handler */
    public function register(string $module, string $resourceType, string $handler): void
    {
        $this->handlers[$this->key($module, $resourceType)] = $handler;
    }

    public function has(string $module, string $resourceType): bool
    {
        return isset($this->handlers[$this->key($module, $resourceType)]);
    }

    public function handler(string $module, string $resourceType): ApprovalHandler
    {
        $class = $this->handlers[$this->key($module, $resourceType)]
            ?? throw new LogicException("No approval handler is registered for {$module} / {$resourceType}.");

        /** @var ApprovalHandler */
        return $this->container->make($class);
    }

    /** @return list<string> "module/resource_type" pairs, for the coverage test */
    public function registered(): array
    {
        return array_keys($this->handlers);
    }

    private function key(string $module, string $resourceType): string
    {
        return $module.'/'.$resourceType;
    }
}
