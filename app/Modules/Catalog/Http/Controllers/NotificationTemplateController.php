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
use App\Modules\Catalog\Actions\NotificationTemplateWriter;
use App\Modules\Catalog\Http\Resources\V1\NotificationTemplateResource;
use App\Modules\Catalog\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/notification-templates. Filters: notification_category_id, event_category_id.
 *
 * @extends RecordController<NotificationTemplate>
 */
final class NotificationTemplateController extends RecordController
{
    protected string $key = 'template';

    protected string $module = 'notification_templates';

    protected string $type = 'notification_template';

    /** @var list<string> */
    protected array $detail = ['notificationCategory', 'category'];

    protected array $messages = [
        'created' => 'Notification template created successfully',
        'updated' => 'Notification template updated successfully',
        'status' => 'Notification template status updated successfully',
        'deleted' => 'Notification template deleted successfully',
    ];

    public function __construct(Approvals $approvals, private readonly NotificationTemplateWriter $writer)
    {
        parent::__construct($approvals);
    }

    public function variables(): JsonResponse
    {
        return ApiResponse::success(['variables' => array_map(
            static fn (string $key): array => ['key' => $key, 'label' => '{{'.$key.'}}'],
            NotificationTemplateWriter::VARIABLES,
        )]);
    }

    public function systemTriggers(): JsonResponse
    {
        return ApiResponse::success(['triggers' => NotificationTemplateWriter::TRIGGERS]);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $copy = $this->writer->duplicate($this->writer->find($id), $this->actor($request));

        return ApiResponse::created([$this->key => $this->present($this->fresh($copy))], 'Notification template duplicated successfully');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        $notification = $request->query('notification_category_id');
        $event = $request->query('event_category_id');

        return new CallbackListQuery(
            NotificationTemplate::query()->with(['notificationCategory', 'category']),
            ['name', 'title', 'content'],
            ['sort_order', 'name', 'created_at'],
            true,
            static function (Builder $query) use ($notification, $event): void {
                foreach (['notification_category_id' => $notification, 'event_category_id' => $event] as $column => $value) {
                    if ($value === 'null') {
                        $query->whereNull($column);
                    } elseif (is_numeric($value) && (int) $value > 0) {
                        $query->where($column, (int) $value);
                    }
                }
            },
        );
    }

    protected function present(Model $model): V1Resource
    {
        return new NotificationTemplateResource($model);
    }
}
