<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * One slot's text in one language.
 *
 * @property int $id
 * @property int $site_id
 * @property string $section
 * @property string $page_slug
 * @property int $record_id
 * @property string $field_key
 * @property int $language_id
 * @property string|null $value
 * @property string $status
 */
final class SiteTranslation extends SiteRecord
{
    protected $table = 'site_translations';

    protected $fillable = ['site_id', 'section', 'page_slug', 'record_id', 'field_key', 'language_id', 'value', 'status'];

    protected function casts(): array
    {
        return ['record_id' => 'integer', 'language_id' => 'integer'];
    }
}
