<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A subject a visitor can pick on the contact form.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteContactCategory extends SiteRecord
{
    protected $table = 'site_contact_categories';

    protected $fillable = ['site_id', 'name', 'slug', 'description', 'sort_order', 'is_active'];
}
