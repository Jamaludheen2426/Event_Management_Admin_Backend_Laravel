<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A logo in the clients or sponsors strip (type).
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteLogo extends SiteRecord
{
    protected $table = 'site_logos';

    protected $fillable = ['site_id', 'type', 'name', 'logo_url', 'website_url', 'sort_order', 'is_active'];
}
