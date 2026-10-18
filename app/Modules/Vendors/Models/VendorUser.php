<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Models;

use App\Core\Database\SerializesV1Dates;
use App\Core\Tenancy\BelongsToVendor;
use App\Core\Tenancy\ResolvesTenant;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Models\Role;
use App\Modules\Vendors\Database\Factories\VendorUserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Someone who signs in for a vendor: the owner (is_owner, the v1 vendors
 * login) or a staff member (v1 vendor_staff). Guard: vendor.
 *
 * The owner passes every vendor permission. Staff hold one vendor role
 * (spatie team = vendor id).
 *
 * @property int $id
 * @property int $vendor_id
 * @property bool $is_owner
 * @property int|null $department_id
 * @property string|null $emp_id
 * @property string $name
 * @property string $email
 * @property string|null $mobile
 * @property string|null $password
 * @property string|null $profile_pic
 * @property bool $login_access
 * @property bool $is_active
 * @property string $work_status
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $last_login_at
 */
final class VendorUser extends Authenticatable implements HasApiTokensContract, ResolvesTenant
{
    use BelongsToVendor;
    use HasApiTokens;

    /** @use HasFactory<VendorUserFactory> */
    use HasFactory;

    use HasRoles;
    use SerializesV1Dates;
    use SoftDeletes;

    protected string $guard_name = 'vendor';

    protected $fillable = [
        'vendor_id', 'is_owner', 'department_id', 'emp_id', 'name', 'email', 'mobile', 'password', 'profile_pic',
        'designation', 'dob', 'doj', 'dor', 'address', 'country', 'state', 'district', 'city', 'locality', 'pincode',
        'login_access', 'is_active', 'work_status', 'last_login_at', 'password_changed_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_owner' => 'boolean',
            'login_access' => 'boolean',
            'is_active' => 'boolean',
            'dob' => 'date',
            'doj' => 'date',
            'dor' => 'date',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Emails are stored trimmed and lower-cased, so sign-in is case-insensitive.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(static fn (?string $value): string => mb_strtolower(trim((string) $value)));
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<VendorDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(VendorDepartment::class, 'department_id');
    }

    /** The staff member's single vendor role (v1 vendor_staff.role_id). */
    public function role(): ?Role
    {
        /** @var Role|null */
        return $this->roles->first();
    }

    /** Find a live login by email in any vendor (sign-in, reset). */
    public static function findByEmail(string $email, ?bool $owner = null): ?self
    {
        return Tenancy::bypass(static fn (): ?self => self::query()
            ->where('email', mb_strtolower(trim($email)))
            ->when($owner !== null, static fn ($q) => $q->where('is_owner', $owner))
            ->first());
    }

    public function tenantCompanyId(): int
    {
        return (int) $this->vendor->company_id;
    }

    public function tenantVendorId(): int
    {
        return $this->vendor_id;
    }

    public function permissionTeamId(): int
    {
        return $this->vendor_id;
    }

    protected static function newFactory(): VendorUserFactory
    {
        return VendorUserFactory::new();
    }
}
