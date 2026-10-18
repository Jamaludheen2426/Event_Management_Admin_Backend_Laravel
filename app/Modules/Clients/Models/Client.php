<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\SerializesV1Dates;
use App\Core\Tenancy\BelongsToVendor;
use App\Core\Tenancy\ResolvesTenant;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Clients\Database\Factories\ClientFactory;
use App\Modules\Clients\Enums\ClientSource;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * A vendor's customer — v1 website_clients and vendor_clients in one table.
 * Signs in to the client portal, the mobile app and the v1 vendor-client
 * portal through the client guard.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string|null $code
 * @property ClientSource $source
 * @property string|null $provider_id
 * @property string $name
 * @property string|null $company_name
 * @property string|null $bio
 * @property string|null $email
 * @property string|null $dial_code
 * @property string|null $mobile
 * @property string|null $password
 * @property string|null $avatar_url
 * @property int|null $plan_id
 * @property int|null $plan_type_id
 * @property string $registration_type
 * @property bool $newsletter_opt_in
 * @property bool $login_access
 * @property bool $send_credentials_to_email
 * @property bool $email_verified
 * @property bool $mobile_verified
 * @property string|null $otp_hash
 * @property Carbon|null $otp_expires_at
 * @property int $otp_attempts
 * @property int $is_active
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $created_at
 */
final class Client extends Authenticatable implements HasApiTokensContract, ResolvesTenant
{
    use BelongsToVendor;
    use HasApiTokens;

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use SerializesV1Dates;
    use SoftDeletes;

    protected $fillable = [
        'vendor_id', 'code', 'source', 'provider_id', 'name', 'company_name', 'bio', 'email', 'dial_code', 'mobile',
        'password', 'avatar_url', 'address', 'country', 'state', 'district', 'city', 'locality', 'pincode',
        'plan_id', 'plan_type_id', 'registration_type', 'newsletter_opt_in', 'login_access',
        'send_credentials_to_email', 'email_verified', 'mobile_verified', 'otp_hash', 'otp_expires_at',
        'otp_attempts', 'is_active', 'last_login_at', 'password_changed_at', 'created_by',
    ];

    protected $hidden = ['password', 'otp_hash', 'remember_token'];

    /** The column defaults, so a freshly created client answers them without a reload. */
    protected $attributes = [
        'source' => 'website',
        'dial_code' => '+91',
        'registration_type' => 'client',
        'newsletter_opt_in' => false,
        'login_access' => true,
        'send_credentials_to_email' => false,
        'email_verified' => false,
        'mobile_verified' => false,
        'otp_attempts' => 0,
        'is_active' => 1,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'source' => ClientSource::class,
            'newsletter_opt_in' => 'boolean',
            'login_access' => 'boolean',
            'send_credentials_to_email' => 'boolean',
            'email_verified' => 'boolean',
            'mobile_verified' => 'boolean',
            'is_active' => 'integer',
            'otp_attempts' => 'integer',
            'otp_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /**
     * Emails are stored trimmed and lower-cased.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::set(static function (?string $value): ?string {
            $value = mb_strtolower(trim((string) $value));

            return $value === '' ? null : $value;
        });
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<PlanType, $this> */
    public function planType(): BelongsTo
    {
        return $this->belongsTo(PlanType::class)->withoutGlobalScopes();
    }

    /** @return HasMany<ClientFavourite, $this> */
    public function favourites(): HasMany
    {
        return $this->hasMany(ClientFavourite::class)->orderBy('sort_order');
    }

    /** @return HasOne<ClientTwoFactor, $this> */
    public function twoFactor(): HasOne
    {
        return $this->hasOne(ClientTwoFactor::class);
    }

    public function isActive(): bool
    {
        return $this->is_active === 1;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    public function tenantCompanyId(): int
    {
        return (int) $this->vendor->company_id;
    }

    public function tenantVendorId(): int
    {
        return $this->vendor_id;
    }

    public function permissionTeamId(): ?int
    {
        return null;
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}
