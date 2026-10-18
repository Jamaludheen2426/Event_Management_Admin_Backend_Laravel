<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A block a page shows (hero, testimonials...), its order and look.
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteBlock extends SiteRecord
{
    protected $table = 'site_blocks';

    protected $fillable = ['site_id', 'page_slug', 'block_key', 'label', 'variant_key', 'is_visible', 'sort_order', 'config_json', 'design_json', 'mobile_json', 'is_active'];

    protected function casts(): array
    {
        return ['config_json' => 'array', 'design_json' => 'array', 'mobile_json' => 'array'];
    }
}
