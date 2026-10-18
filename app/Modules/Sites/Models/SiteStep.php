<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A step of "How it works".
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteStep extends SiteRecord
{
    protected $table = 'site_steps';

    protected $fillable = ['site_id', 'step_number', 'title', 'description', 'highlight_title', 'highlight_subtext', 'icon', 'illustration_url', 'is_active', 'sort_order'];
}
