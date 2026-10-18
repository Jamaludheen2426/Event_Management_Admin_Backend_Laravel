<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A feature card (Features page).
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteFeature extends SiteRecord
{
    protected $table = 'site_features';

    protected $fillable = ['site_id', 'title', 'short_description', 'detailed_description', 'icon', 'custom_icon_url', 'feature_image_url', 'bullet_points_json', 'show_in_menu', 'menu_order', 'status', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['bullet_points_json' => 'array'];
    }
}
