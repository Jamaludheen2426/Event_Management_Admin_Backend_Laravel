<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A link to the site owner's profile on a social network.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteSocialLink extends SiteRecord
{
    protected $table = 'site_social_links';

    protected $fillable = ['site_id', 'icon_key', 'icon_color', 'label', 'url', 'sort_order', 'is_active'];
}
