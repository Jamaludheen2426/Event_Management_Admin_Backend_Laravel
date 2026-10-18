<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Users;

use App\Core\Activity\ActivityLogger;
use App\Core\Approvals\ApprovalHandler;
use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Mail\AccountStatusMail;
use App\Modules\Identity\Models\Department;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Every change to an admin employee (v1 /users, module "employees").
 *
 * Also the approval handler for employees: an approved request runs the same
 * code, as the person who asked for it.
 *
 * Input uses v1 keys; location keys are translated here (v1 city_id = district,
 * pincode_id = city).
 */
final class UserWriter implements ApprovalHandler
{
    /** v1 key => column, for the plain fields an admin may set. */
    private const FIELDS = [
        'full_name' => 'full_name', 'email' => 'email', 'username' => 'username', 'phone' => 'phone',
        'avatar' => 'avatar', 'dob' => 'dob', 'gender' => 'gender', 'marital_status' => 'marital_status',
        'designation' => 'designation', 'doj' => 'doj', 'dor' => 'dor', 'address' => 'address',
        'country_id' => 'country_id', 'state_id' => 'state_id', 'city_id' => 'district_id', 'pincode_id' => 'city_id',
        'department_id' => 'department_id', 'timezone' => 'timezone', 'login_access' => 'login_access',
        'password' => 'password',
    ];

    public function __construct(
        private readonly SessionTokens $tokens,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $input validated v1 input */
    public function create(array $input, User $actor, bool $pending = false): User
    {
        $this->assertEmailFree((string) $input['email']);
        $role = $this->assignableRole($input['role_id'] ?? null, $actor);
        $this->assertDepartment($input['department_id'] ?? null);

        return DB::transaction(function () use ($input, $actor, $role, $pending): User {
            $user = new User($this->columns($input));
            $user->is_active = $pending ? false : (int) ($input['is_active'] ?? 0) === 1;
            $user->approval_status = $pending ? 'pending' : 'approved';
            $user->created_by = $actor->id;
            $user->save();

            if ($role !== null) {
                $user->syncRoles([$role]);
            }

            $this->activity->log('employees', 'create', "Created user: {$user->email}", $user, [], $input);

            return $user;
        });
    }

    /** @param array<string, mixed> $input validated v1 input (only the sent keys) */
    public function update(User $user, array $input, User $actor): User
    {
        if (isset($input['email']) && $input['email'] !== $user->email) {
            $this->assertEmailFree((string) $input['email'], $user->id);
        }
        $this->assertDepartment($input['department_id'] ?? null);

        $currentRole = $user->loadMissing('roles')->role();
        $role = null;
        if (isset($input['role_id']) && (int) $input['role_id'] !== $currentRole?->id) {
            // v1 skipped the level check on update, so a role could be raised
            // above the editor's own level by editing instead of creating.
            $role = $this->assignableRole($input['role_id'], $actor);
            $this->assertCanManage($user, $actor);
        }

        $old = $user->only([...array_diff(array_values(self::FIELDS), ['password']), 'is_active']);

        return DB::transaction(function () use ($user, $input, $actor, $role, $old): User {
            $user->fill($this->columns($input));
            if (array_key_exists('is_active', $input) && in_array((int) $input['is_active'], [0, 1], true)) {
                $user->is_active = (int) $input['is_active'] === 1;
            }
            if (isset($input['password'])) {
                $user->password_changed_at = now();
            }
            $user->updated_by = $actor->id;
            $user->save();

            if ($role !== null) {
                $user->syncRoles([$role]);
            }

            // A password set by someone else, or a deactivation, ends the user's sessions.
            if ((isset($input['password']) && $actor->id !== $user->id) || ! $user->is_active) {
                $this->tokens->revokeAll($user);
            }

            $this->activity->log('employees', 'update', "Updated user: {$user->email}", $user, $old, $input);

            return $user;
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->id === $actor->id) {
            throw ApiException::badRequest('You cannot delete your own account');
        }
        $this->assertCanManage($user, $actor);

        DB::transaction(function () use ($user, $actor): void {
            $this->tokens->revokeAll($user);
            $user->forceFill(['updated_by' => $actor->id])->save();
            $user->delete();
            $this->activity->log('employees', 'delete', "Deleted user: {$user->email}", $user);
        });
    }

    /** v1 PATCH /users/:id/status — 1 active, 0 inactive. */
    public function setStatus(User $user, int $isActive, User $actor): User
    {
        if ($user->id === $actor->id && $isActive !== 1) {
            throw ApiException::badRequest('You cannot deactivate your own account');
        }

        $wasActive = $user->is_active;
        $user->forceFill(['is_active' => $isActive === 1, 'updated_by' => $actor->id])->save();

        if ($wasActive && ! $user->is_active) {
            $this->tokens->revokeAll($user);
        }

        $this->activity->log('employees', 'update_status', 'Changed employee is_active from '.(int) $wasActive." to {$isActive}", $user);

        if ($wasActive !== $user->is_active) {
            try {
                Mail::to($user->email)->send(new AccountStatusMail($user->full_name, $user->is_active));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $user;
    }

    // ---- ApprovalHandler ------------------------------------------------

    public function snapshot(int $id): ?array
    {
        $user = User::query()->find($id);

        return $user?->makeHidden(['password', 'remember_token'])->attributesToArray();
    }

    public function createPending(array $payload, Model $requester): int
    {
        /** @var User $requester */
        return $this->create($payload, $requester, pending: true)->id;
    }

    public function apply(string $action, ?int $resourceId, array $payload, Model $requester): ?Model
    {
        /** @var User $requester */
        $user = $resourceId === null ? null : User::query()->find($resourceId);
        if ($user === null) {
            throw ApiException::notFound('User not found');
        }

        if ($action === 'create') {
            $user->forceFill(['approval_status' => 'approved', 'is_active' => true])->save();

            return $user;
        }

        if ($action === 'delete') {
            $this->delete($user, $requester);

            return null;
        }

        return $this->update($user, $payload, $requester);
    }

    public function discard(int $resourceId, Model $reviewer): void
    {
        User::query()->whereKey($resourceId)->where('approval_status', 'pending')->first()?->forceDelete();
    }

    // ---- rules ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function columns(array $input): array
    {
        $columns = [];
        foreach (self::FIELDS as $key => $column) {
            if (array_key_exists($key, $input)) {
                $columns[$column] = $input[$key];
            }
        }
        if (array_key_exists('login_access', $columns)) {
            $columns['login_access'] = (int) $columns['login_access'] === 1;
        }
        if (($columns['password'] ?? null) === null) {
            unset($columns['password']);
        }

        return $columns;
    }

    /** Emails are unique across all companies, among live accounts. */
    private function assertEmailFree(string $email, ?int $exceptId = null): void
    {
        $taken = Tenancy::bypass(static fn (): bool => User::query()
            ->where('email', $email)
            ->when($exceptId !== null, static fn ($q) => $q->whereKeyNot($exceptId))
            ->exists());

        if ($taken) {
            throw ApiException::conflict('Email already exists');
        }
    }

    private function assignableRole(mixed $roleId, User $actor): ?Role
    {
        if ($roleId === null) {
            return null;
        }

        $role = Role::query()
            ->where('guard_name', 'admin')
            ->where('team_id', $actor->company_id)
            ->find((int) $roleId);

        if ($role === null) {
            throw ApiException::badRequest('Invalid role');
        }

        if (! $actor->bypassesPermissions() && $role->level > $actor->roleLevel()) {
            throw ApiException::forbidden('Cannot assign a role with higher level than your own');
        }

        return $role;
    }

    /** Nobody below developer/super admin may change someone ranked at or above them. */
    private function assertCanManage(User $target, User $actor): void
    {
        $target->loadMissing('roles');
        if (! $actor->bypassesPermissions() && $target->id !== $actor->id && $target->roleLevel() >= $actor->roleLevel()) {
            throw ApiException::forbidden('Cannot modify a user with an equal or higher role level');
        }
    }

    private function assertDepartment(mixed $departmentId): void
    {
        if ($departmentId !== null && ! Department::query()->whereKey((int) $departmentId)->exists()) {
            throw ApiException::badRequest('Invalid department');
        }
    }
}
