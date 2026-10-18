<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToVendor;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A website. The house vendor owns the marketing site (kind marketing, edited
 * from the admin panel's Website Builder); any vendor may own one vendor site
 * (the separate builder app). Visitors reach a site by its slug under a
 * public-site root domain, or by its custom domain.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $kind
 * @property string $slug
 * @property string|null $custom_domain
 * @property string $status
 * @property string|null $theme_id
 * @property int|null $palette_id
 * @property int $publish_version
 * @property Carbon|null $published_at
 * @property array<string, mixed>|null $settings_json
 * @property int $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Vendor $vendor
 */
final class Site extends Model
{
    use BelongsToVendor;
    use SoftDeletes;

    public const MARKETING = 'marketing';

    public const VENDOR = 'vendor';

    protected $fillable = [
        'vendor_id', 'kind', 'slug', 'custom_domain', 'status', 'theme_id', 'palette_id',
        'publish_version', 'published_at', 'settings_json', 'is_active',
    ];

    protected $attributes = ['status' => 'draft', 'publish_version' => 0, 'is_active' => 1];

    protected function casts(): array
    {
        return ['settings_json' => 'array', 'published_at' => 'datetime', 'publish_version' => 'integer'];
    }

    public function isMarketing(): bool
    {
        return $this->kind === self::MARKETING;
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
