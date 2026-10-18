<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\Setting;
use Illuminate\Http\Request;

/**
 * @mixin Setting
 *
 * @property Setting $resource
 */
final class SettingResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $setting = $this->resource;

        return [
            'id' => $setting->id,
            'company_id' => $setting->company_id,
            'key' => $setting->key,
            'value' => $setting->value,
            'group' => $setting->group,
            'type' => $setting->type,
            'description' => $setting->getAttribute('description'),
            'is_active' => $this->flag($setting->is_active),
            'created_by' => $setting->getAttribute('created_by'),
            'updated_by' => $setting->getAttribute('updated_by'),
            'created_at' => $this->isoDate($setting->created_at),
            'updated_at' => $this->isoDate($setting->updated_at),
        ];
    }
}
