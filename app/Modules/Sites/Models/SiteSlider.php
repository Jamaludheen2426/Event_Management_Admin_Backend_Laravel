<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A slider (simple or advanced); its slides are SiteSlide rows.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteSlider extends SiteRecord
{
    protected $table = 'site_sliders';

    protected $fillable = ['site_id', 'slider_type', 'title', 'slider_height', 'autoplay', 'autoplay_speed', 'status', 'config_json', 'is_active'];

    protected function casts(): array
    {
        return ['config_json' => 'array'];
    }
}
