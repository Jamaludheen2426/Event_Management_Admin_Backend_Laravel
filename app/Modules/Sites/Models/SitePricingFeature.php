<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A row of the pricing comparison table.
 *
 * @property int $id
 * @property int $site_id
 */
final class SitePricingFeature extends SiteRecord
{
    protected $table = 'site_pricing_features';

    protected $fillable = ['site_id', 'feature_name', 'icon', 'description', 'category', 'plan_values_json', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['plan_values_json' => 'array'];
    }
}
