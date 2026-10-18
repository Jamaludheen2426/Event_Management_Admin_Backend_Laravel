<?php

declare(strict_types=1);

namespace App\Core\Approvals;

use Illuminate\Database\Eloquent\Model;

/**
 * Who needs approval for what. Implemented by the Identity module (roles and
 * permissions live there) and bound in its provider, so Core never imports it.
 */
interface ApprovalPolicy
{
    /** True when this actor's role marks "<module>.<action>" as needing approval. */
    public function requiresApproval(Model $actor, string $module, string $action): bool;

    /** True when this actor may approve or reject requests (role level >= 100). */
    public function canReview(Model $actor): bool;
}
