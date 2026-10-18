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
use App\Core\Support\Flag;
use App\Modules\Templates\Actions\EventTemplateWriter;
use App\Modules\Templates\Http\Resources\V1\EventTemplateResource;
use App\Modules\Templates\Models\EventTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/event-templates. Filters: event_category_id, template_category_id,
 * style, is_featured, status (active | inactive | draft | published), publish_status.
 *
 * @extends RecordController<EventTemplate>
 */
final class EventTemplateController extends RecordController
{
    private const RELATIONS = ['category', 'templateCategory', 'frameStyle', 'decorations', 'plans'];

    protected string $key = 'template';

    protected string $module = 'event_templates';

    protected string $type = 'event_template';

    /** @var list<string> */
    protected array $detail = [...self::RELATIONS, 'creator', 'updater'];

    protected array $messages = [
        'created' => 'Template created successfully',
        'updated' => 'Template updated successfully',
        'status' => 'Template status updated successfully',
        'deleted' => 'Template deleted successfully',
        'reordered' => 'Template order updated successfully',
    ];

    public function __construct(Approvals $approvals, private readonly EventTemplateWriter $writer)
    {
        parent::__construct($approvals);
    }

    public function stats(): JsonResponse
    {
        $rows = EventTemplate::query()->selectRaw('is_active, count(*) as total, coalesce(sum(is_featured), 0) as featured')->groupBy('is_active')->get();
        $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'featured' => 0];
        foreach ($rows as $row) {
            $count = (int) $row->getAttribute('total');
            $stats['total'] += $count;
            $stats['featured'] += (int) $row->getAttribute('featured');
            $stats[(int) $row->getAttribute('is_active') === 1 ? 'active' : 'inactive'] += $count;
        }

        return ApiResponse::success(['stats' => $stats]);
    }

    public function featured(Request $request, int $id): JsonResponse
    {
        $template = $this->writer->setFeatured($this->writer->find($id), $request->input('is_featured'), $this->actor($request));

        return ApiResponse::success([$this->key => $this->present($this->fresh($template))], 'Template updated successfully');
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $copy = $this->writer->duplicate($this->writer->find($id), $this->actor($request));

        return ApiResponse::created([$this->key => $this->present($this->fresh($copy))], 'Template duplicated successfully');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        $category = $request->query('event_category_id');
        $styleCategory = $request->query('template_category_id');
        $style = strtolower((string) $request->query('style', ''));
        $featured = $request->query('is_featured');
        $status = strtolower((string) $request->query('status', ''));
        $publish = strtolower((string) $request->query('publish_status', ''));

        return (new CallbackListQuery(
            EventTemplate::query()->with(self::RELATIONS),
            ['name', 'code', 'description'],
            ['sort_order', 'name', 'code', 'created_at', 'updated_at'],
            true,
            static function (Builder $query) use ($category, $styleCategory, $style, $featured, $status, $publish): void {
                if (is_numeric($category)) {
                    $query->where('event_category_id', (int) $category);
                }
                if (is_numeric($styleCategory)) {
                    $query->where('template_category_id', (int) $styleCategory);
                }
                if ($style !== '' && $style !== 'all') {
                    $query->where('style', $style);
                }
                if ($featured !== null && $featured !== '' && $featured !== 'all') {
                    $query->where('is_featured', Flag::bit($featured));
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
            },
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new EventTemplateResource($model);
    }
}
