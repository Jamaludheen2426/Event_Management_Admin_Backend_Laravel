<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Core\Support\Flag;
use App\Modules\Catalog\Actions\EventMenuWriter;
use App\Modules\Catalog\Http\Resources\V1\EventMenuResource;
use App\Modules\Catalog\Models\EventMenu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/event-menus. Filters: event_category_id, menu_group, is_default.
 *
 * @extends RecordController<EventMenu>
 */
final class EventMenuController extends RecordController
{
    protected string $key = 'eventMenu';

    protected string $module = 'event_menus';

    protected string $type = 'event_menu';

    /** @var list<string> */
    protected array $detail = ['category', 'creator', 'updater'];

    protected array $messages = [
        'created' => 'Menu created successfully',
        'updated' => 'Menu updated successfully',
        'status' => 'Menu status updated successfully',
        'deleted' => 'Menu deleted successfully',
        'reordered' => 'Menu order updated successfully',
    ];

    public function __construct(Approvals $approvals, private readonly EventMenuWriter $writer)
    {
        parent::__construct($approvals);
    }

    /** PATCH /event-menus/:id/toggle/:field { value } */
    public function toggle(Request $request, int $id, string $field): JsonResponse
    {
        $menu = $this->writer->toggle($this->writer->find($id), $field, $request->input('value'), $this->actor($request));

        return ApiResponse::success([$this->key => $this->present($this->fresh($menu))], 'Menu updated successfully');
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $menu = $this->writer->duplicate($this->writer->find($id), $this->actor($request));

        return ApiResponse::created([$this->key => $this->present($this->fresh($menu))], 'Menu duplicated successfully');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        $category = $request->query('event_category_id');
        $group = $request->query('menu_group');
        $default = $request->query('is_default');

        return (new CallbackListQuery(
            EventMenu::query()->with('category'),
            ['name', 'slug'],
            ['sort_order', 'name', 'created_at'],
            true,
            static function (Builder $query) use ($category, $group, $default): void {
                if (is_numeric($category)) {
                    $query->where('event_category_id', (int) $category);
                }
                if (is_string($group) && $group !== '' && $group !== 'all') {
                    $query->where('menu_group', strtolower($group));
                }
                if ($default !== null && $default !== '' && $default !== 'all') {
                    $query->where('is_default', Flag::bit($default));
                }
            },
        ))->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new EventMenuResource($model);
    }
}
