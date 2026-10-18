<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use App\Modules\Templates\Actions\EventTemplateWriter;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Models\EventTemplate;
use Illuminate\Http\Request;

/**
 * Event template in v1 keys. decoration_ids / plan_ids come from the pivots;
 * components / permissions / component_order are always complete maps
 * (v1 shape()); decorationItems lists the decorations in order.
 *
 * @property EventTemplate $resource
 */
final class EventTemplateResource extends RowResource
{
    protected function extra(Request $request): array
    {
        $t = $this->resource;
        $decorations = $t->relationLoaded('decorations') ? $t->decorations : collect();
        $availableFor = is_array($t->available_for) && $t->available_for !== [] ? $t->available_for : EventTemplate::AUDIENCES;

        return [
            'tags' => is_array($t->tags) ? $t->tags : [],
            'available_for' => $availableFor,
            'components' => EventTemplateWriter::flags($t->components ?? [], EventTemplate::COMPONENT_KEYS),
            'permissions' => EventTemplateWriter::flags($t->permissions ?? [], EventTemplate::PERMISSION_KEYS),
            'component_order' => EventTemplateWriter::order($t->component_order ?? []),
            'decoration_ids' => $decorations->pluck('id')->values()->all(),
            'plan_ids' => $t->relationLoaded('plans') ? $t->plans->pluck('id')->values()->all() : [],
            'decorationItems' => $decorations->map(static fn (Decoration $d): array => $d->only(['id', 'name', 'type', 'file_url', 'is_active']))->values()->all(),
            ...$this->brief('category', ['id', 'name', 'color']),
            ...$this->brief('templateCategory', ['id', 'name', 'slug']),
            ...$this->brief('frameStyle', ['id', 'name', 'file_url', 'supported_layouts', 'template_category_id']),
            ...$this->authors(),
        ];
    }
}
