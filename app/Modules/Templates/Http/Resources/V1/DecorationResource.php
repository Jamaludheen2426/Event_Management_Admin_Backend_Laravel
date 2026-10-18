<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use App\Modules\Templates\Actions\DecorationWriter;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Support\SvgArtwork;
use Illuminate\Http\Request;

/**
 * Decoration row + type_label, file_size_label ("245 KB"), creator / updater.
 *
 * @property Decoration $resource
 */
final class DecorationResource extends RowResource
{
    protected function extra(Request $request): array
    {
        return [
            'type_label' => DecorationWriter::TYPES[$this->resource->type] ?? $this->resource->type,
            'file_size_label' => SvgArtwork::size($this->resource->file_size),
            ...$this->authors(),
        ];
    }
}
