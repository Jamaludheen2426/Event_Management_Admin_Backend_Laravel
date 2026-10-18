<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A group of FAQs.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteFaqCategory extends SiteRecord
{
    protected $table = 'site_faq_categories';

    protected $fillable = ['site_id', 'name', 'description', 'icon', 'color', 'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by'];
}
