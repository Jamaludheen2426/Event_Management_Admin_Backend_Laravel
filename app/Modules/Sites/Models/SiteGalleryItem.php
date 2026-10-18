<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A photo of the site's gallery.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteGalleryItem extends SiteRecord
{
    protected $table = 'site_gallery_items';

    protected $fillable = ['site_id', 'category_id', 'event_name', 'event_type', 'city', 'image_url', 'alt_text', 'sort_order', 'is_active'];
}
