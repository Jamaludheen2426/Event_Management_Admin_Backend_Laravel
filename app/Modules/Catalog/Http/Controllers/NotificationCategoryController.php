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
use App\Modules\Catalog\Actions\NotificationCategoryWriter;
use App\Modules\Catalog\Models\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * /api/v1/notification-categories
 *
 * @extends RecordController<NotificationCategory>
 */
final class NotificationCategoryController extends RecordController
{
    protected string $key = 'notificationCategory';

    protected string $module = 'notification_categories';

    protected string $type = 'notification_category';

    protected array $messages = [
        'created' => 'Notification category created successfully',
        'updated' => 'Notification category updated successfully',
        'status' => 'Notification category status updated successfully',
        'deleted' => 'Notification category deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly NotificationCategoryWriter $writer)
    {
        parent::__construct($approvals);
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return (new CallbackListQuery(NotificationCategory::query(), ['name', 'description'], ['sort_order', 'name', 'created_at'], true))
            ->defaultOrder('sort_order');
    }

    protected function present(Model $model): V1Resource
    {
        return new RowResource($model);
    }
}
