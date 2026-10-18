<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A page of the site: the fixed ones (about, terms...) and the owner's own.
 *
 * @property int $id
 * @property int $site_id
 */
final class SitePage extends SiteRecord
{
    protected $table = 'site_pages';

    protected $fillable = ['site_id', 'page_type', 'title', 'slug', 'content', 'excerpt', 'seo_title', 'seo_description', 'seo_keywords', 'og_image_url', 'status', 'sort_order', 'is_system', 'is_active'];
}
