<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * One slide of a slider (v1 slider item).
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteSlide extends SiteRecord
{
    protected $table = 'site_slides';

    protected $fillable = ['site_id', 'slider_id', 'title', 'description', 'image_url', 'button_label', 'button_page_id', 'button_url', 'button_color', 'button_text_color', 'sort_order', 'status', 'is_active'];
}
