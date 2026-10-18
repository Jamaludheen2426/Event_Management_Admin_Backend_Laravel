<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A group of the template showcase.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteTemplateCategory extends SiteRecord
{
    protected $table = 'site_template_categories';

    protected $fillable = ['site_id', 'name', 'slug', 'description', 'icon', 'color', 'sort_order', 'is_active'];
}
