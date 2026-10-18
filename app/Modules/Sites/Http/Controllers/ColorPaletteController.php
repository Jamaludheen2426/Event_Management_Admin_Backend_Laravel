<?php

declare(strict_types=1);

namespace App\Modules\Sites\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\RowResource;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Sites\Actions\ColorPaletteWriter;
use App\Modules\Sites\Models\ColorPalette;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * /api/v1/color-palettes
 *
 * @extends RecordController<ColorPalette>
 */
final class ColorPaletteController extends RecordController
{
    protected string $key = 'palette';

    protected string $module = 'color_palettes';

    protected string $type = 'color_palettes';

    protected array $messages = [
        'created' => 'Color palette created successfully',
        'updated' => 'Color palette updated successfully',
        'status' => 'Color palette status updated successfully',
        'deleted' => 'Color palette deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly ColorPaletteWriter $writer)
    {
        parent::__construct($approvals);
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return (new CallbackListQuery(ColorPalette::query(), ['name'], ['created_at', 'name'], true))->defaultOrder('created_at', 'desc');
    }

    protected function present(Model $model): V1Resource
    {
        return new RowResource($model);
    }
}
