<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use App\Modules\Templates\Models\TemplateFont;
use Illuminate\Http\Request;

/**
 * Font row + link_kind: for a link, "file" when it points at a font file,
 * "stylesheet" otherwise (Google Fonts css2); null for uploads.
 *
 * @property TemplateFont $resource
 */
final class TemplateFontResource extends RowResource
{
    protected function extra(Request $request): array
    {
        $font = $this->resource;

        return [
            'link_kind' => $font->source === 'link'
                ? (TemplateFont::formatOf($font->link_url) !== null ? 'file' : 'stylesheet')
                : null,
        ];
    }
}
