<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question and its answer.
 *
 * @property int $id
 * @property int $site_id
 * @property-read SiteFaqCategory|null $category
 */
final class SiteFaq extends SiteRecord
{
    protected $table = 'site_faqs';

    protected $fillable = ['site_id', 'faq_category_id', 'question', 'answer', 'tags', 'is_featured', 'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by'];

    /** @return BelongsTo<SiteFaqCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SiteFaqCategory::class, 'faq_category_id');
    }
}
