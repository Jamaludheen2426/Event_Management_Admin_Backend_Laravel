<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use Illuminate\Support\Carbon;

/**
 * One of a site's single-row sections (basic information, hero, footer, SEO,
 * login page, theme, contact settings, pricing headings): its fields as a
 * JSON document. Which fields a section has is SiteSections' business.
 *
 * @property int $id
 * @property int $site_id
 * @property string $section
 * @property array<string, mixed>|null $data
 * @property int $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SiteSetting extends SiteRecord
{
    protected $table = 'site_settings';

    protected $fillable = ['site_id', 'section', 'data', 'is_active'];

    protected $attributes = ['is_active' => 1];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
