<?php

declare(strict_types=1);

namespace App\Core\Approvals;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * One change waiting for a reviewer. Company-scoped: a reviewer can only ever
 * see and act on requests of their own company (the Node API let any level-100
 * user approve another company's request by id).
 *
 * @property int $id
 * @property int $company_id
 * @property int $requester_id
 * @property int|null $approver_id
 * @property string $module_slug
 * @property string $permission_slug
 * @property string $action
 * @property string $resource_type
 * @property int|null $resource_id
 * @property array<string, mixed>|null $request_data
 * @property array<string, mixed>|null $old_data
 * @property ApprovalStatus $status
 * @property string|null $review_notes
 * @property Carbon|null $reviewed_at
 */
final class ApprovalRequest extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'requester_id', 'approver_id', 'module_slug', 'permission_slug', 'action',
        'resource_type', 'resource_id', 'request_data', 'old_data', 'status', 'review_notes', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_data' => 'array',
            'old_data' => 'array',
            'status' => ApprovalStatus::class,
            'reviewed_at' => 'datetime',
            'resource_id' => 'integer',
        ];
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }
}
