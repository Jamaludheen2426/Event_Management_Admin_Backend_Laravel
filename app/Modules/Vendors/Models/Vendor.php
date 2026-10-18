<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use App\Core\Tenancy\Tenancy;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Platform\Models\City;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\District;
use App\Modules\Vendors\Database\Factories\VendorFactory;
use App\Modules\Vendors\Enums\VendorStatus;
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor business account (platform layer: it belongs to the company).
 *
 * Every customer-facing row — clients, events, sites, vendor users — belongs to
 * exactly one vendor. Vendor #1 (config tenancy.house_vendor_id) is the HOUSE
 * vendor: the platform owner's own account. There is no "NULL vendor".
 *
 * Logins live in vendor_users (owner + staff), not here.
 *
 * @property int $id
 * @property int $company_id
 * @property string $company_name
 * @property VendorStatus $status
 */
final class Vendor extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'company_id', 'company_name', 'logo', 'short_description', 'about_us', 'company_information',
        'email', 'phone', 'website', 'address',
        'contact_mode', 'alt_email', 'alt_phone', 'alt_address',
        'country_id', 'state_id', 'district_id', 'city_id', 'latitude', 'longitude', 'plan_type_id',
        'reg_no', 'gst_no', 'landline', 'copywrite', 'poweredby', 'website_enabled', 'status',
        'bank_name', 'account_number', 'ifsc_code', 'account_type', 'branch', 'bank_logo',
    ];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
            'website_enabled' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'account_number' => 'encrypted',
        ];
    }

    public static function houseId(): int
    {
        return (int) config('tenancy.house_vendor_id');
    }

    /** The platform owner's own vendor account. */
    public static function house(): self
    {
        return Tenancy::bypass(static fn (): self => self::query()->findOrFail(self::houseId()));
    }

    /** @var (Closure(string): ?int)|null */
    private static ?Closure $siteResolver = null;

    /**
     * Sites tells how a site's own address (its slug or domain) names its
     * vendor, so the public sign-up and sign-in forms work on either address.
     *
     * @param  Closure(string): ?int  $resolver  slug → vendor id
     */
    public static function resolveSlugsUsing(Closure $resolver): void
    {
        self::$siteResolver = $resolver;
    }

    /**
     * An active vendor by its public slug: its site's address, else the company
     * name, lower case, runs of other characters as "-" (v1 toSlug).
     */
    public static function findActiveBySlug(string $slug): ?self
    {
        $slug = mb_strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        $siteVendor = self::$siteResolver !== null ? (self::$siteResolver)($slug) : null;
        if ($siteVendor !== null) {
            $vendor = Tenancy::bypass(static fn (): ?self => self::query()->where('status', VendorStatus::Active)->find($siteVendor, ['id', 'company_id', 'company_name', 'status']));
            if ($vendor !== null) {
                return $vendor;
            }
        }

        return Tenancy::bypass(static fn (): ?self => self::query()
            ->where('status', VendorStatus::Active)
            ->get(['id', 'company_id', 'company_name', 'status'])
            ->first(static fn (self $v): bool => trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim((string) $v->company_name))), '-') === $slug));
    }

    public function isHouse(): bool
    {
        return $this->id === self::houseId();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The owner login (v1: the vendors row itself).
     *
     * @return HasOne<VendorUser, $this>
     */
    public function owner(): HasOne
    {
        return $this->hasOne(VendorUser::class)->withoutGlobalScopes()->whereNull('deleted_at')->where('is_owner', true);
    }

    /** @return HasMany<VendorUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(VendorUser::class)->withoutGlobalScopes()->whereNull('deleted_at');
    }

    /**
     * The vendor's subscription tier (v1 `membership`, stored by name).
     *
     * @return BelongsTo<PlanType, $this>
     */
    public function planType(): BelongsTo
    {
        return $this->belongsTo(PlanType::class)->withoutGlobalScopes();
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

    protected static function newFactory(): VendorFactory
    {
        return VendorFactory::new();
    }
}
