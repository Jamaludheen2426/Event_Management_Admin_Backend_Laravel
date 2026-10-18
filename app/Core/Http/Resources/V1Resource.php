<?php

declare(strict_types=1);

namespace App\Core\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Base for every v1 resource. A resource is where the clean schema is mapped
 * back to the exact keys the Node API answered with, so the database can change
 * while the frontends do not.
 *
 * No "data" wrapper here: ApiResponse owns the envelope.
 */
abstract class V1Resource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** A DATETIME as v1 sent it: "2026-10-17T10:00:00.000Z". */
    protected function isoDate(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** A DATE column as v1 sent it: "2026-10-17". */
    protected function dateOnly(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }

    /** v1 answered booleans as tinyint 1 / 0. */
    protected function flag(mixed $value): int
    {
        return $value ? 1 : 0;
    }

    /**
     * v1's tri-state is_active: 2 while waiting for approval, else 1 / 0.
     * The frontends grey out and lock rows on 2.
     */
    protected function legacyActive(mixed $isActive, ?string $approvalStatus): int
    {
        return $approvalStatus === 'pending' ? 2 : $this->flag($isActive);
    }

    private ?bool $pendingApproval = null;

    /** Set by PendingApprovals::annotate() on list rows. */
    public function withPendingApproval(bool $pending): static
    {
        $this->pendingApproval = $pending;

        return $this;
    }

    /**
     * v1 list rows of approvable modules carry has_pending_approval (an open
     * update or delete request), so the screens can lock that row.
     *
     * @return array{has_pending_approval?: bool}
     */
    protected function pendingApprovalField(): array
    {
        return $this->pendingApproval === null ? [] : ['has_pending_approval' => $this->pendingApproval];
    }
}
