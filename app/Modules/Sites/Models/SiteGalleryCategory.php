<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * An album of the site's gallery.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteGalleryCategory extends SiteRecord
{
    protected $table = 'site_gallery_categories';

    protected $fillable = ['site_id', 'name', 'slug', 'description', 'sort_order', 'is_active'];
}
