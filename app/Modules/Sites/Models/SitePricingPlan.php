<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A plan card of the pricing page (display only; not a billing plan).
 *
 * @property int $id
 * @property int $site_id
 */
final class SitePricingPlan extends SiteRecord
{
    protected $table = 'site_pricing_plans';

    protected $fillable = ['site_id', 'plan_name', 'subtitle', 'target_type', 'currency', 'price_monthly', 'price_yearly', 'period_label', 'badge_text', 'badge_style', 'is_popular', 'features_json', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['features_json' => 'array'];
    }
}
