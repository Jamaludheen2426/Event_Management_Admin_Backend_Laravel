<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * The highlight cards at one position of one page (settings_json is the editor's document).
 *
 * @property int $id
 * @property int $site_id
 */
final class SiteHighlight extends SiteRecord
{
    protected $table = 'site_highlights';

    protected $fillable = ['site_id', 'page_slug', 'instance', 'settings_json'];

    protected function casts(): array
    {
        return ['settings_json' => 'array'];
    }
}
