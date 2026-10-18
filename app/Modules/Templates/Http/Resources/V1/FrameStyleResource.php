<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use App\Modules\Templates\Actions\FrameStyleWriter;
use App\Modules\Templates\Models\FrameStyle;
use Illuminate\Http\Request;

/**
 * Frame style row with layouts normalised, supported_layouts_label,
 * category {id, name, slug}, creator / updater on detail.
 *
 * @property FrameStyle $resource
 */
final class FrameStyleResource extends RowResource
{
    private const LABELS = ['portrait' => 'Portrait', 'landscape' => 'Landscape', 'square' => 'Square'];

    protected function extra(Request $request): array
    {
        $layouts = FrameStyleWriter::layouts($this->resource->supported_layouts ?? []);

        return [
            'supported_layouts' => $layouts,
            'supported_layouts_label' => implode(', ', array_map(static fn (string $l): string => self::LABELS[$l], $layouts)),
            ...$this->brief('category', ['id', 'name', 'slug']),
            ...$this->authors(),
        ];
    }
}
