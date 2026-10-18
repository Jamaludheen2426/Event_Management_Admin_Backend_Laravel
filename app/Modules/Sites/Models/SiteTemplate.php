<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A design shown in the template showcase.
 *
 * @property int $id
 * @property int $site_id
 * @property-read SiteTemplateCategory|null $category
 */
final class SiteTemplate extends SiteRecord
{
    protected $table = 'site_templates';

    protected $fillable = ['site_id', 'category_id', 'template_name', 'slug', 'description', 'template_type', 'design_style', 'primary_color', 'thumbnail_url', 'template_file_url', 'preview_url', 'is_active', 'allow_customize', 'is_draft', 'is_popular', 'sort_order'];

    /** @return BelongsTo<SiteTemplateCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SiteTemplateCategory::class, 'category_id');
    }
}
