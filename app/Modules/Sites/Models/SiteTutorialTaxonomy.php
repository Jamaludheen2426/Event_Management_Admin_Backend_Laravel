<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video tutorial category, subcategory (parent_id), difficulty level or type (kind).
 *
 * @property int $id
 * @property int $site_id
 * @property-read SiteTutorialTaxonomy|null $parent
 */
final class SiteTutorialTaxonomy extends SiteRecord
{
    protected $table = 'site_tutorial_taxonomies';

    protected $fillable = ['site_id', 'kind', 'parent_id', 'name', 'description', 'icon', 'color', 'sort_order', 'is_active'];

    /** @return BelongsTo<SiteTutorialTaxonomy, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SiteTutorialTaxonomy::class, 'parent_id');
    }
}
