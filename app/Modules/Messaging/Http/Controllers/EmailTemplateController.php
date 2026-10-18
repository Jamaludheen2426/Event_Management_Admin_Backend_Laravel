<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Controllers\RecordController;
use App\Core\Http\Resources\RowResource;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\CallbackListQuery;
use App\Core\Queries\ListQuery;
use App\Modules\Messaging\Actions\EmailTemplates;
use App\Modules\Messaging\Actions\EmailTemplateWriter;
use App\Modules\Messaging\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/email-templates (v1 emailTemplate.controller).
 *
 * @extends RecordController<EmailTemplate>
 */
final class EmailTemplateController extends RecordController
{
    protected string $key = 'template';

    protected string $module = 'email_templates';

    protected string $type = 'email_template';

    protected array $detail = ['emailConfig:id,name,driver,from_email', 'header:id,name,body', 'footer:id,name,body'];

    protected array $messages = [
        'created' => 'Email template created successfully',
        'updated' => 'Email template updated successfully',
        'status' => 'Status updated successfully',
        'deleted' => 'Email template deleted successfully',
    ];

    public function __construct(
        Approvals $approvals,
        private readonly EmailTemplateWriter $writer,
        private readonly EmailTemplates $templates,
    ) {
        parent::__construct($approvals);
    }

    /** v1 answered empty lists here; the editor picks parts from the full list. */
    public function parts(): JsonResponse
    {
        return ApiResponse::success(['headers' => [], 'footers' => []]);
    }

    public function variables(): JsonResponse
    {
        return ApiResponse::success(['variables' => $this->templates->variables()]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $template = $this->writer->toggle($this->writer->find($id), $this->actor($request));

        return ApiResponse::success([$this->key => $this->present($this->fresh($template))], $this->messages['status']);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $variables = $request->input('variables');

        return ApiResponse::success($this->templates->preview($this->writer->find($id), is_array($variables) ? $variables : []));
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $variables = $request->input('variables');

        return ApiResponse::success($this->templates->send($this->writer->find($id), $request->input('to'), is_array($variables) ? $variables : []), 'Email sent successfully');
    }

    protected function writer(): RecordWriter
    {
        return $this->writer;
    }

    protected function listQuery(Request $request): ListQuery
    {
        return new CallbackListQuery(EmailTemplate::query()->with(['emailConfig:id,name,driver,from_email', 'header:id,name', 'footer:id,name']),
            ['name', 'slug', 'subject'], ['created_at', 'name', 'slug'], true);
    }

    protected function present(Model $model): V1Resource
    {
        return new class($model) extends RowResource
        {
            protected function extra(Request $request): array
            {
                // The list loads parts as {id, name}; the detail screen adds their body.
                $part = fn (string $relation): array => $this->brief($relation, array_keys(array_intersect_key(
                    ['id' => 1, 'name' => 1, 'body' => 1],
                    $this->resource->relationLoaded($relation) ? (array) $this->resource->getRelation($relation)?->getAttributes() : [],
                )));

                return [
                    ...$this->brief('emailConfig', ['id', 'name', 'driver', 'from_email'], 'email_config'),
                    ...$part('header'),
                    ...$part('footer'),
                ];
            }
        };
    }
}
