<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Approvals\ApprovalRequest;
use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * An approval request in v1 keys: status travels as is_active (2 pending,
 * 1 approved, 0 rejected). requester / approver are filled by the controller
 * (users are looked up in one query per page).
 *
 * @mixin ApprovalRequest
 *
 * @property ApprovalRequest $resource
 */
final class ApprovalResource extends V1Resource
{
    /** @var array<int, User> */
    private array $users = [];

    /** @param array<int, User> $users id => user */
    public function withUsers(array $users): self
    {
        $this->users = $users;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $approval = $this->resource;

        return [
            'id' => $approval->id,
            'company_id' => $approval->company_id,
            'requester_id' => $approval->requester_id,
            'approver_id' => $approval->approver_id,
            'module_slug' => $approval->module_slug,
            'permission_slug' => $approval->permission_slug,
            'action' => $approval->action,
            'resource_type' => $approval->resource_type,
            'resource_id' => $approval->resource_id,
            'request_data' => $approval->request_data,
            'old_data' => $approval->old_data,
            'is_active' => $approval->status->toLegacyFlag(),
            'review_notes' => $approval->review_notes,
            'reviewed_at' => $this->isoDate($approval->reviewed_at),
            'created_at' => $this->isoDate($approval->created_at),
            'updated_at' => $this->isoDate($approval->updated_at),
            'requester' => $this->person($approval->requester_id, withRole: true),
            'approver' => $this->person($approval->approver_id),
        ];
    }

    /** @return array<string, mixed>|null */
    private function person(?int $id, bool $withRole = false): ?array
    {
        $user = $id === null ? null : ($this->users[$id] ?? null);
        if ($user === null) {
            return null;
        }

        $person = ['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email];
        if ($withRole && $user->relationLoaded('roles')) {
            $person['role_id'] = $user->role()?->id;
        }

        return $person;
    }
}
