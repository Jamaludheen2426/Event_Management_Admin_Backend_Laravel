<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\Language;
use Illuminate\Http\Request;

/**
 * @mixin Language
 *
 * @property Language $resource
 */
final class LanguageResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $language = $this->resource;

        return [
            'id' => $language->id,
            'name' => $language->name,
            'code' => $language->code,
            'native_name' => $language->getAttribute('native_name'),
            'direction' => $language->direction,
            'is_default' => $language->is_default,
            'is_active' => $this->legacyActive($language->is_active, $language->approval_status),
            'created_by' => $language->getAttribute('created_by'),
            'updated_by' => $language->getAttribute('updated_by'),
            'created_at' => $this->isoDate($language->created_at),
            'updated_at' => $this->isoDate($language->updated_at),
            ...$this->pendingApprovalField(),
        ];
    }
}
