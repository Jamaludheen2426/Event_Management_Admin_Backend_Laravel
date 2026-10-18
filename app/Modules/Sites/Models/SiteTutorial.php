<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A video tutorial.
 *
 * @property int $id
 * @property int $site_id
 * @property-read SiteTutorialTaxonomy|null $category
 * @property-read SiteTutorialTaxonomy|null $subcategory
 * @property-read SiteTutorialTaxonomy|null $difficulty
 * @property-read SiteTutorialTaxonomy|null $type
 */
final class SiteTutorial extends SiteRecord
{
    protected $table = 'site_tutorials';

    protected $fillable = ['site_id', 'title', 'short_description', 'category_id', 'subcategory_id', 'tags_json', 'video_source', 'video_url', 'video_file_url', 'duration_seconds', 'difficulty_level_id', 'tutorial_type_id', 'key_takeaways', 'thumbnail_url', 'is_featured', 'publish_date', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['tags_json' => 'array', 'publish_date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<SiteTutorialTaxonomy, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SiteTutorialTaxonomy::class, 'category_id');
    }

    /** @return BelongsTo<SiteTutorialTaxonomy, $this> */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(SiteTutorialTaxonomy::class, 'subcategory_id');
    }

    /** @return BelongsTo<SiteTutorialTaxonomy, $this> */
    public function difficulty(): BelongsTo
    {
        return $this->belongsTo(SiteTutorialTaxonomy::class, 'difficulty_level_id');
    }

    /** @return BelongsTo<SiteTutorialTaxonomy, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(SiteTutorialTaxonomy::class, 'tutorial_type_id');
    }
}
