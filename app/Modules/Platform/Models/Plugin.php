<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;

/**
 * An integration switch (payment gateway, storage, social login...). The list
 * is predefined (PluginCatalogue); each company gets its own on/off rows.
 *
 * @property int $id
 * @property int $company_id
 * @property string $slug
 * @property string $name
 * @property string $category
 * @property bool $is_active
 * @property string|null $config_group
 */
final class Plugin extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'slug', 'name', 'description', 'category', 'icon', 'is_active', 'config_group', 'config_route'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
