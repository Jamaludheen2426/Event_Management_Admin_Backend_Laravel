<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Messaging\Actions\EmailConfigChecks;
use App\Modules\Messaging\Actions\EmailConfigWriter;
use App\Modules\Messaging\Http\Resources\V1\EmailConfigResource;
use App\Modules\Messaging\Models\EmailConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/email-configs (v1 emailConfig.controller).
 *
 * @extends RecordController<EmailConfig>
 */
final class EmailConfigController extends RecordController
{
    protected string $key = 'emailConfig';

    protected string $module = 'email_configs';

    protected string $type = 'email_config';

    protected string $updateAction = 'edit';

    protected array $messages = [
        'created' => 'Email configuration created successfully',
        'updated' => 'Email configuration updated successfully',
        'status' => 'Email configuration status updated successfully',
        'deleted' => 'Email configuration deleted successfully',
    ];

    public function __construct(
        Approvals $approvals,
        private readonly EmailConfigWriter $writer,
        private readonly EmailConfigChecks $checks,
    ) {
        parent::__construct($approvals);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $templateId = $request->input('template_id');

        return ApiResponse::success($this->checks->test(
            $this->writer->find($id),
            $request->filled('test_email') ? (string) $request->input('test_email') : null,
            is_numeric($templateId) ? (int) $templateId : null,
        ));
    }

    public function debug(int $id): JsonResponse
    {
        return ApiResponse::success($this->checks->debug($this->writer->find($id)));
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $config = $this->writer->toggle($this->writer->find($id), $this->actor($request));

        return ApiResponse::success([$this->key => $this->present($config)], $this->messages['status']);
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return new CallbackListQuery(EmailConfig::query(), ['name', 'from_email', 'driver'], ['created_at', 'name', 'driver'], true);
    }

    protected function present(Model $model): V1Resource
    {
        return new EmailConfigResource($model);
    }
}
