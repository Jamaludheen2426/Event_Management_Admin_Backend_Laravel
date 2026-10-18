<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A translatable piece of text on the site and its English source: one slot
 * (section, page_slug, record_id, field_key).
 *
 * @property int $id
 * @property int $site_id
 * @property string $section
 * @property string $page_slug
 * @property int $record_id
 * @property string $field_key
 * @property string $field_label
 * @property string|null $default_value
 * @property int $sort_order
 */
final class SiteTranslationKey extends SiteRecord
{
    protected $table = 'site_translation_keys';

    protected $fillable = ['site_id', 'section', 'page_slug', 'record_id', 'field_key', 'field_label', 'default_value', 'sort_order'];

    protected function casts(): array
    {
        return ['record_id' => 'integer', 'sort_order' => 'integer'];
    }
}
