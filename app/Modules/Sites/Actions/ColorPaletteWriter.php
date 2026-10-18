<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Modules\Sites\Models\ColorPalette;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin colour palettes (/color-palettes). v1's edit and delete answered 404
 * for every palette (bug 3); here they work like any other list screen.
 *
 * @extends RecordWriter<ColorPalette>
 */
final class ColorPaletteWriter extends RecordWriter
{
    protected function model(): string
    {
        return ColorPalette::class;
    }

    protected function module(): string
    {
        return 'color_palettes';
    }

    protected function label(): string
    {
        return 'Color palette';
    }

    protected function columns(): array
    {
        return ['name', 'primary_bg_color', 'primary_text_color', 'secondary_text_color', 'paragraph_color', 'is_active'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if (array_key_exists('name', $values) || $existing === null) {
            $values['name'] = trim(is_scalar($values['name'] ?? null) ? (string) $values['name'] : '');
            if ($values['name'] === '') {
                throw ApiException::badRequest('Name is required');
            }
        }

        return $values;
    }
}
