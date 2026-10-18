<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Closure;

/**
 * Holds one tenant id for the current request.
 *
 * Registered with $app->scoped(), so every HTTP request (and every queued job)
 * starts empty. Reading an unset context throws instead of returning null:
 * a query that silently ran without its tenant filter is exactly the bug class
 * this architecture exists to remove.
 */
abstract class TenantContext
{
    private ?int $id = null;

    public function set(int $id): void
    {
        $this->id = $id;
    }

    public function has(): bool
    {
        return $this->id !== null;
    }

    public function id(): int
    {
        return $this->id ?? throw TenantNotResolved::for(static::label());
    }

    public function forget(): void
    {
        $this->id = null;
    }

    /**
     * Run a callback as another tenant, then restore the previous one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(int $id, Closure $callback): mixed
    {
        $previous = $this->id;
        $this->id = $id;

        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }

    abstract protected static function label(): string;
}
