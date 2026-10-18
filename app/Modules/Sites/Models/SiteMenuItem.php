<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * An entry of the site's navigation menu (parent_id: a submenu entry).
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteMenuItem extends SiteRecord
{
    protected $table = 'site_menu_items';

    protected $fillable = ['site_id', 'parent_id', 'label', 'item_type', 'page_id', 'url', 'target', 'sort_order', 'is_visible', 'is_active'];
}
