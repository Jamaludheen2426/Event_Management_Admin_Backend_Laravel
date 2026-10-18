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
use App\Modules\Templates\Actions\FrameStyleWriter;
use App\Modules\Templates\Http\Resources\V1\FrameStyleResource;
use App\Modules\Templates\Models\FrameStyle;
use App\Modules\Templates\Support\SvgArtwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/frame-styles. Filters: template_category_id, status
 * (active | inactive | draft | published), publish_status, layout.
 *
 * @extends RecordController<FrameStyle>
 */
final class FrameStyleController extends RecordController
{
    protected string $key = 'frameStyle';

    protected string $module = 'frame_styles';

    protected string $type = 'frame_style';

    /** @var list<string> */
    protected array $detail = ['category', 'creator', 'updater'];

    protected array $messages = [
        'created' => 'Frame style uploaded successfully',
        'updated' => 'Frame style updated successfully',
        'status' => 'Frame style status updated successfully',
        'deleted' => 'Frame style deleted successfully',
    ];

    public function __construct(
        Approvals $approvals,
        private readonly FrameStyleWriter $writer,
        private readonly SvgArtwork $artwork,
    ) {
        parent::__construct($approvals);
    }

    public function stats(): JsonResponse
    {
        $rows = FrameStyle::query()->selectRaw('is_active, status, count(*) as total')->groupBy('is_active', 'status')->get();
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'draft' => 0];
        foreach ($rows as $row) {
            $count = (int) $row->getAttribute('total');
            $stats['total'] += $count;
            $stats[(int) $row->getAttribute('is_active') === 1 ? 'active' : 'inactive'] += $count;
            $stats['draft'] += $row->getAttribute('status') === 'draft' ? $count : 0;
        }

        return ApiResponse::success(['stats' => $stats]);
    }

    public function svgSource(Request $request): JsonResponse
    {
        return ApiResponse::success($this->artwork->source((string) $request->query('file_url'), 'frame style'), 'Frame style source loaded');
    }

    public function recolor(Request $request): JsonResponse
    {
        $result = $this->artwork->recolor(
            (string) $request->input('file_url'), $request->input('color_map'), $request->input('file_name'), 'frame style', 'frame-styles',
        );

        return ApiResponse::success($result, 'Frame style recoloured');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        $category = $request->query('template_category_id');
        $status = strtolower((string) $request->query('status', ''));
        $publish = strtolower((string) $request->query('publish_status', ''));
        $layout = strtolower((string) $request->query('layout', ''));

        return (new CallbackListQuery(
            FrameStyle::query()->with('category'),
            ['name'],
            ['sort_order', 'name', 'created_at', 'updated_at'],
            true,
            static function (Builder $query) use ($category, $status, $publish, $layout): void {
                if (is_numeric($category)) {
                    $query->where('template_category_id', (int) $category);
                }
                match ($status) {
                    'active' => $query->where('is_active', 1),
                    'inactive' => $query->where('is_active', 0),
                    'draft', 'published' => $query->where('status', $status),
                    default => null,
                };
                if (in_array($publish, ['draft', 'published'], true)) {
                    $query->where('status', $publish);
                }
                if (in_array($layout, FrameStyleWriter::LAYOUTS, true)) {
                    $query->whereJsonContains('supported_layouts', $layout);
                }
            },
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new FrameStyleResource($model);
    }
}
