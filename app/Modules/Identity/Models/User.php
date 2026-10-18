<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Database\SerializesV1Dates;
use App\Core\Tenancy\BelongsToCompany;
use App\Core\Tenancy\ResolvesTenant;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Platform\Models\City;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\Country;
use App\Modules\Platform\Models\District;
use App\Modules\Platform\Models\State;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * An admin-panel user (platform layer). Guard: admin.
 *
 * Admin users work on the HOUSE vendor's data (website builder, clients), so
 * their tenant vendor is configuration, not a column. One role per user, as in
 * v1 (spatie allows several; the admin screens assign exactly one).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $department_id
 * @property string $full_name
 * @property string $email
 * @property string|null $password
 * @property bool $is_active
 * @property bool $login_access
 * @property string $approval_status
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
final class User extends Authenticatable implements HasApiTokensContract, ResolvesTenant
{
    use BelongsToCompany;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use SerializesV1Dates;
    use SoftDeletes;

    protected string $guard_name = 'admin';

    protected $fillable = [
        'company_id', 'department_id', 'full_name', 'email', 'username', 'phone', 'avatar', 'password',
        'dob', 'gender', 'marital_status', 'designation', 'doj', 'dor', 'address',
        'country_id', 'state_id', 'district_id', 'city_id', 'timezone',
        'login_access', 'is_active', 'approval_status',
        'email_verified_at', 'last_login_at', 'password_changed_at', 'created_by', 'updated_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'login_access' => 'boolean',
            'is_active' => 'boolean',
            'dob' => 'date',
            'doj' => 'date',
            'dor' => 'date',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** The user's single role (v1 users.role_id). */
    public function role(): ?Role
    {
        /** @var Role|null */
        return $this->roles->first();
    }

    public function bypassesPermissions(): bool
    {
        return (bool) $this->role()?->bypassesPermissions();
    }

    public function roleLevel(): int
    {
        return (int) ($this->role()->level ?? 0);
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->approval_status === 'approved';
    }

    public function tenantCompanyId(): int
    {
        return (int) $this->company_id;
    }

    public function tenantVendorId(): int
    {
        return (int) config('tenancy.house_vendor_id');
    }

    public function permissionTeamId(): int
    {
        return (int) $this->company_id;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
