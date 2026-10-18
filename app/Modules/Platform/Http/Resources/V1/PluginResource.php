<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\Plugin;
use Illuminate\Http\Request;

/**
 * @mixin Plugin
 *
 * @property Plugin $resource
 */
final class PluginResource extends V1Resource
{
    public function __construct(Plugin $plugin, private readonly int $usageCount = 0)
    {
        parent::__construct($plugin);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $plugin = $this->resource;

        return [
            'id' => $plugin->id,
            'company_id' => $plugin->company_id,
            'slug' => $plugin->slug,
            'name' => $plugin->name,
            'description' => $plugin->getAttribute('description'),
            'category' => $plugin->category,
            'icon' => $plugin->getAttribute('icon'),
            'is_active' => $this->flag($plugin->is_active),
            'config_group' => $plugin->config_group,
            'config_route' => $plugin->getAttribute('config_route'),
            'usage_count' => $this->usageCount,
            'created_at' => $this->isoDate($plugin->created_at),
            'updated_at' => $this->isoDate($plugin->updated_at),
        ];
    }
}
