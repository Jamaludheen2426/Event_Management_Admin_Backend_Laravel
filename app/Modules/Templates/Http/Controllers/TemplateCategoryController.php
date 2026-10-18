<?php

declare(strict_types=1);

namespace App\Modules\Templates\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\RowResource;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Templates\Actions\TemplateCategoryWriter;
use App\Modules\Templates\Models\TemplateCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/template-categories — template styles; list rows carry frame_styles_count.
 *
 * @extends RecordController<TemplateCategory>
 */
final class TemplateCategoryController extends RecordController
{
    protected string $key = 'category';

    protected string $module = 'template_categories';

    protected string $type = 'template_category';

    protected array $messages = [
        'created' => 'Category created successfully',
        'updated' => 'Category updated successfully',
        'status' => 'Category status updated successfully',
        'deleted' => 'Category deleted successfully',
        'reordered' => 'Category order updated successfully',
    ];

    public function __construct(Approvals $approvals, private readonly TemplateCategoryWriter $writer)
    {
        parent::__construct($approvals);
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->listQuery($request)->paginate($request);
        $ids = array_map(static fn (Model $m): int => (int) $m->getKey(), $page->items());
        $pending = PendingApprovals::for($this->module, $this->type, $ids);

        return ApiResponse::paginated($page->through(static fn (TemplateCategory $c): array => [
            ...(new RowResource($c))->withPendingApproval(isset($pending[$c->id]))->toArray($request),
            'frame_styles_count' => (int) $c->getAttribute('frame_styles_count'),
        ]));
    }

    /** Deleting a category leaves its frames in place, uncategorised — and says so. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = $this->writer->find($id);
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, $this->module, 'delete', $this->type, $category->id, [],
            function () use ($category, $actor): JsonResponse {
                $orphaned = $this->writer->deleteCounting($category, $actor);
                $message = $orphaned > 0
                    ? "Category deleted. {$orphaned} frame style".($orphaned === 1 ? '' : 's').' became uncategorised.'
                    : 'Category deleted successfully';

                return ApiResponse::success(['orphaned_frame_styles' => $orphaned], $message);
            });
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return (new CallbackListQuery(
            TemplateCategory::query()->withCount('frameStyles'),
            ['name', 'slug'],
            ['sort_order', 'name', 'slug', 'created_at', 'updated_at'],
            true,
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new RowResource($model);
    }
}
