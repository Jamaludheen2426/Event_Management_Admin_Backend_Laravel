<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\RowResource;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Catalog\Actions\EventCategoryWriter;
use App\Modules\Catalog\Models\EventCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * /api/v1/event-categories
 *
 * @extends RecordController<EventCategory>
 */
final class EventCategoryController extends RecordController
{
    protected string $key = 'eventCategory';

    protected string $module = 'event_categories';

    protected string $type = 'event_category';

    protected array $messages = [
        'created' => 'Event category created successfully',
        'updated' => 'Event category updated successfully',
        'status' => 'Event category status updated successfully',
        'deleted' => 'Event category deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly EventCategoryWriter $writer)
    {
        parent::__construct($approvals);
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return (new CallbackListQuery(EventCategory::query(), ['name', 'description'], ['sort_order', 'name', 'created_at'], true))
            ->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new RowResource($model);
    }
}
