<?php

declare(strict_types=1);

namespace App\Core\Approvals;

use Illuminate\Database\Eloquent\Model;

/**
 * How one approvable resource is changed. Each module implements this for the
 * resources it owns and registers it in its service provider:
 *
 *   ApprovalRegistry::register('roles', 'role', RoleApprovalHandler::class);
 *
 * Replaces the Node API's hard-coded service map, which forgot 8 modules and
 * made approving their requests fail with "No service found".
 */
interface ApprovalHandler
{
    /**
     * The row as it is now, for the reviewer to compare (secrets removed).
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(int $id): ?array;

    /**
     * A create that needs approval inserts its row immediately, marked
     * pending, so it already shows in lists (greyed out). Returns its id.
     *
     * @param  array<string, mixed>  $payload  validated input
     */
    public function createPending(array $payload, Model $requester): int;

    /**
     * Run an approved change as the person who asked for it.
     *
     * action: create (activate the pending row), update / edit, delete.
     *
     * @param  array<string, mixed>  $payload
     */
    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model;

    /** A rejected create: remove the pending row. */
    public function discard(int $resourceId, Model $reviewer): void;
}
