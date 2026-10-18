<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Auth\SessionTokens;
use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Models\Role;
use App\Modules\Vendors\Models\VendorDepartment;
use App\Modules\Vendors\Models\VendorUser;
use App\Modules\Vendors\Support\VendorPassword;
use Illuminate\Support\Facades\DB;

/**
 * A vendor's staff (v1 vendor_staff), managed by the owner in the vendor
 * portal or by staff with staff.* permissions in the staff portal.
 *
 * v1 security rules kept (CLAUDE.md):
 *  - field whitelists per caller (STAFF_CREATABLE / STAFF_EDITABLE / VENDOR_EDITABLE);
 *  - only the owner assigns roles (create with role_id, PUT /staff/:id/role);
 *  - staff cannot change login access, active state or passwords of others;
 *  - nobody deletes or deactivates themselves.
 */
final class StaffWriter
{
    /** The owner creating staff (v1 STAFF_CREATABLE_FIELDS). */
    private const CREATABLE = [
        'name', 'email', 'mobile', 'password', 'designation', 'doj', 'dob', 'dor', 'address', 'country', 'state',
        'district', 'city', 'locality', 'pincode', 'work_status', 'profile_pic', 'department_id', 'login_access',
    ];

    /** The owner editing staff (v1 VENDOR_EDITABLE_FIELDS). */
    private const OWNER_EDITABLE = [
        'name', 'mobile', 'designation', 'doj', 'dob', 'dor', 'address', 'country', 'state', 'district', 'city',
        'locality', 'pincode', 'work_status', 'profile_pic', 'login_access', 'password', 'department_id',
    ];

    /** Staff editing staff in the staff portal (v1 STAFF_EDITABLE_FIELDS). */
    private const STAFF_EDITABLE = ['name', 'mobile', 'designation', 'doj', 'address', 'work_status'];

    /** Fields the staff portal may never send (v1 VENDOR_ONLY_FIELDS). */
    private const OWNER_ONLY = ['role_id', 'password', 'is_active', 'login_access', 'emp_id', 'vendor_id'];

    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly SessionTokens $tokens,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, VendorUser $actor): VendorUser
    {
        if (! $actor->is_owner && array_key_exists('role_id', $input)) {
            throw ApiException::forbidden('Role assignment is not allowed from the staff portal. Only the vendor can assign roles.');
        }
        $role = $actor->is_owner && ! empty($input['role_id']) ? $this->vendorRole($input['role_id'], 'Invalid role: role does not belong to this vendor', 400) : null;

        $values = $this->pick($input, self::CREATABLE);
        foreach (['name' => 'Name is required', 'email' => 'Email is required', 'mobile' => 'Mobile number is required'] as $field => $message) {
            if (trim((string) ($values[$field] ?? '')) === '') {
                throw ApiException::badRequest($message);
            }
        }
        $values['email'] = mb_strtolower(trim((string) $values['email']));
        if (! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            throw ApiException::badRequest('Enter a valid email address');
        }
        $this->assertEmailFree($values['email']);
        if (array_key_exists('password', $values) && $values['password'] !== null && $values['password'] !== '') {
            VendorPassword::assertValid($values['password']);
        } else {
            unset($values['password']);
        }

        return DB::transaction(function () use ($values, $role, $actor): VendorUser {
            $staff = VendorUser::query()->create([
                ...$values,
                'is_owner' => false,
                'is_active' => true,
                'emp_id' => $this->nextEmpId(),
            ]);
            if ($role !== null) {
                $staff->syncRoles([$role]);
            }
            $this->activity->log('staff', 'create', "Created staff: {$staff->email}", $staff, causer: $actor);

            return $staff;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(VendorUser $staff, array $input, VendorUser $actor): VendorUser
    {
        if (! $actor->is_owner) {
            $restricted = array_values(array_filter(self::OWNER_ONLY, static fn (string $f): bool => array_key_exists($f, $input)));
            if ($restricted !== []) {
                throw ApiException::forbidden('Field(s) ['.implode(', ', $restricted).'] can only be changed by the vendor');
            }
        }

        $values = $this->pick($input, $actor->is_owner ? self::OWNER_EDITABLE : self::STAFF_EDITABLE);
        $newPassword = $values['password'] ?? null;
        unset($values['password']);
        if ($newPassword !== null && $newPassword !== '') {
            VendorPassword::assertValid($newPassword);
            $values['password'] = $newPassword;
            $values['password_changed_at'] = now();
        }

        $staff->fill($values)->save();

        if (isset($values['password']) || (array_key_exists('login_access', $values) && ! $staff->login_access)) {
            $this->tokens->revokeAll($staff);
        }
        $this->activity->log('staff', 'update', "Updated staff: {$staff->email}", $staff, causer: $actor);

        return $staff;
    }

    public function setActive(VendorUser $staff, mixed $isActive, VendorUser $actor): VendorUser
    {
        if ($staff->id === $actor->id) {
            throw ApiException::forbidden('You cannot change your own account status');
        }
        $staff->forceFill(['is_active' => Flag::bit($isActive, 1) === 1])->save();
        if (! $staff->is_active) {
            $this->tokens->revokeAll($staff);
        }

        return $staff;
    }

    public function delete(VendorUser $staff, VendorUser $actor): void
    {
        if ($staff->id === $actor->id) {
            throw ApiException::forbidden('You cannot delete your own account');
        }
        $this->tokens->revokeAll($staff);
        $staff->delete();
        $this->activity->log('staff', 'delete', "Deleted staff: {$staff->email}", $staff, causer: $actor);
    }

    /** Owner only (v1 PUT /vendors/staff/:id/role). */
    public function assignRole(VendorUser $staff, mixed $roleId): VendorUser
    {
        $staff->syncRoles([$this->vendorRole($roleId, 'Role not found or does not belong to this vendor', 404)]);

        return $staff->unsetRelation('roles');
    }

    /** @return VendorUser staff only — never the owner */
    public function find(int $id): VendorUser
    {
        return VendorUser::query()->where('is_owner', false)->find($id) ?? throw ApiException::notFound('Staff member not found');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function pick(array $input, array $fields): array
    {
        $values = array_intersect_key($input, array_flip($fields));
        foreach (['doj', 'dob', 'dor'] as $date) {
            if (array_key_exists($date, $values) && ($values[$date] === '' || $values[$date] === 'Invalid date' || strtotime((string) $values[$date]) === false)) {
                $values[$date] = null;
            }
        }
        if (array_key_exists('work_status', $values)) {
            $values['work_status'] = Flag::oneOf($values['work_status'], ['active', 'inactive', 'resigned', 'relieved'], 'active');
        }
        if (array_key_exists('login_access', $values)) {
            $values['login_access'] = Flag::bit($values['login_access'], 1) === 1;
        }
        if (array_key_exists('department_id', $values)) {
            $values['department_id'] = Flag::id($values['department_id']);
            if ($values['department_id'] !== null && ! VendorDepartment::query()->whereKey($values['department_id'])->exists()) {
                throw ApiException::badRequest('Invalid department');
            }
        }
        if (isset($values['profile_pic']) && is_string($values['profile_pic'])) {
            $values['profile_pic'] = $this->storage->storeDataUri($values['profile_pic'], 'staff', 'profile_pic');
        }

        return $values;
    }

    /** EMP-0001, EMP-0002... per vendor, never reusing a number. */
    private function nextEmpId(): string
    {
        $last = VendorUser::query()->withTrashed()->where('is_owner', false)->whereNotNull('emp_id')
            ->orderByDesc('id')->value('emp_id');
        $number = is_string($last) && preg_match('/(\d+)$/', $last, $m) ? (int) $m[1] + 1 : 1;

        return 'EMP-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    private function vendorRole(mixed $roleId, string $message, int $status): Role
    {
        $role = Role::query()->where('guard_name', 'vendor')->where('team_id', getPermissionsTeamId())->find(Flag::id($roleId));

        return $role ?? throw new ApiException($message, $status);
    }

    private function assertEmailFree(string $email): void
    {
        if (Tenancy::bypass(static fn (): bool => VendorUser::query()->where('email', $email)->exists())) {
            throw ApiException::conflict('A staff member or vendor with this email already exists');
        }
    }
}
