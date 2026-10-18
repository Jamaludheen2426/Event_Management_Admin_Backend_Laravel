<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A social link the contact page shows, and where.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteContactSocialLink extends SiteRecord
{
    protected $table = 'site_contact_social_links';

    protected $fillable = ['site_id', 'social_link_id', 'sort_order', 'is_visible', 'is_active'];
}
