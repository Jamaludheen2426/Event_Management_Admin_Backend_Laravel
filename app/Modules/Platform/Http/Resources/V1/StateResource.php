<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Platform\Models\State;
use Illuminate\Http\Request;

/**
 * @mixin State
 *
 * @property State $resource
 */
final class StateResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $state = $this->resource;

        return [
            'id' => $state->id,
            'country_id' => $state->country_id,
            'name' => $state->name,
            'code' => $state->getAttribute('code'),
            'slug' => $state->getAttribute('slug'),
            'sort_order' => $state->getAttribute('sort_order'),
            'is_default' => $this->flag($state->getAttribute('is_default')),
            'is_active' => $this->legacyActive($state->is_active, $state->getAttribute('approval_status')),
            'created_at' => $this->isoDate($state->created_at),
            'updated_at' => $this->isoDate($state->updated_at),
            'country' => $this->whenLoaded('country', fn (): ?array => $state->country === null ? null : [
                'id' => $state->country->id, 'name' => $state->country->name, 'code' => $state->country->code,
            ]),
            ...$this->pendingApprovalField(),
        ];
    }
}
