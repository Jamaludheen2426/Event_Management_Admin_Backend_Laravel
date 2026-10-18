<?php

declare(strict_types=1);

namespace App\Core\Approvals;

/**
 * Lifecycle of an approval request and of a row created under approval.
 *
 * The v1 API exposed this through `is_active` (2 = pending, 1 = approved,
 * 0 = rejected) and the frontends still read it that way, so resources map it
 * back with toLegacyFlag().
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function toLegacyFlag(): int
    {
        return match ($this) {
            self::Pending => 2,
            self::Approved => 1,
            self::Rejected => 0,
        };
    }

    public static function fromLegacyFlag(int|string|null $flag): ?self
    {
        return match ((string) $flag) {
            '2' => self::Pending,
            '1' => self::Approved,
            '0' => self::Rejected,
            default => null,
        };
    }
}
