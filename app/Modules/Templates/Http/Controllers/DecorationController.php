<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Templates\Actions\DecorationWriter;
use App\Modules\Templates\Http\Resources\V1\DecorationResource;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Support\SvgArtwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/decorations. Filters: type, file_format; newest first by default (v1).
 *
 * @extends RecordController<Decoration>
 */
final class DecorationController extends RecordController
{
    protected string $key = 'decoration';

    protected string $module = 'decorations';

    protected string $type = 'decoration';

    /** @var list<string> */
    protected array $detail = ['creator', 'updater'];

    protected array $messages = [
        'created' => 'Decoration uploaded successfully',
        'updated' => 'Decoration updated successfully',
        'status' => 'Decoration status updated successfully',
        'deleted' => 'Decoration deleted successfully',
        'reordered' => 'Decoration order updated successfully',
    ];

    public function __construct(
        Approvals $approvals,
        private readonly DecorationWriter $writer,
        private readonly SvgArtwork $artwork,
    ) {
        parent::__construct($approvals);
    }

    public function stats(): JsonResponse
    {
        $rows = Decoration::query()->selectRaw('is_active, count(*) as total, coalesce(sum(file_size), 0) as bytes')->groupBy('is_active')->get();
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'total_bytes' => 0];
        foreach ($rows as $row) {
            $count = (int) $row->getAttribute('total');
            $stats['total'] += $count;
            $stats['total_bytes'] += (int) $row->getAttribute('bytes');
            $stats[(int) $row->getAttribute('is_active') === 1 ? 'active' : 'inactive'] += $count;
        }
        $stats['total_size_label'] = SvgArtwork::size($stats['total_bytes']) ?? '0 KB';

        return ApiResponse::success(['stats' => $stats]);
    }

    public function svgSource(Request $request): JsonResponse
    {
        return ApiResponse::success($this->artwork->source((string) $request->query('file_url'), 'decoration'), 'Decoration source loaded');
    }

    public function recolor(Request $request): JsonResponse
    {
        $result = $this->artwork->recolor(
            (string) $request->input('file_url'), $request->input('color_map'), $request->input('file_name'), 'decoration', 'decorations',
        );

        return ApiResponse::success($result, 'Decoration recoloured');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        $type = $request->query('type');
        $format = $request->query('file_format');

        return (new CallbackListQuery(
            Decoration::query(),
            ['name', 'file_name'],
            ['created_at', 'name', 'type', 'file_size', 'sort_order', 'updated_at'],
            true,
            static function (Builder $query) use ($type, $format): void {
                if (is_string($type) && $type !== '' && $type !== 'all') {
                    $query->where('type', array_key_exists(strtolower($type), DecorationWriter::TYPES) ? strtolower($type) : null);
                }
                if (is_string($format) && $format !== '' && $format !== 'all') {
                    $query->where('file_format', DecorationWriter::format($format));
                }
            },
        ))->defaultOrder('created_at', 'desc');
    }

    protected function present(Model $model): V1Resource
    {
        return new DecorationResource($model);
    }
}
