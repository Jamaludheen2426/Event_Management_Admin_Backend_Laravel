<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

/**
 * A language the site is offered in. The default one is the language the
 * content is written in; the others get translations.
 *
 * @property int $id
 * @property int $site_id
 * @property string $code
 * @property string $name
 * @property string|null $native_name
 * @property string $direction
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
final class SiteLanguage extends SiteRecord
{
    protected $table = 'site_languages';

    protected $fillable = ['site_id', 'code', 'name', 'native_name', 'direction', 'is_default', 'is_active', 'sort_order'];

    protected $attributes = ['direction' => 'ltr', 'is_default' => false, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_default' => 'integer', 'is_active' => 'integer', 'sort_order' => 'integer'];
    }
}
