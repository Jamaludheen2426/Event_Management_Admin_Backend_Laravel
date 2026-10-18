<?php

declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Approvals\Approvals;
use App\Core\Approvals\PendingApprovals;
use App\Core\Crud\RecordWriter;
use App\Core\Http\ApiResponse;
use App\Core\Http\Resources\V1Resource;
use App\Core\Queries\ListQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The v1 admin list screen as a controller: index, show, store, update,
 * status, destroy, reorder — with the approval workflow on create, update and
 * delete exactly where v1 had it.
 *
 * A module controller extends this, names its writer, list query, resource,
 * response key and messages, and adds its own extra actions.
 *
 * @template TModel of Model
 */
abstract class RecordController
{
    /** v1 response key for one row ("eventCategory"). */
    protected string $key;

    /** Permission module / approval module slug ("event_categories"). */
    protected string $module;

    /** Approval resource type ("event_category"). */
    protected string $type;

    /** v1 sent edits for approval as "update" in most modules, "edit" in some. */
    protected string $updateAction = 'update';

    /**
     * Relations loaded for show and after writes.
     *
     * @var list<string>
     */
    protected array $detail = [];

    /** @var array{created: string, updated: string, status: string, deleted: string, reordered?: string} */
    protected array $messages;

    public function __construct(protected readonly Approvals $approvals) {}

    /** @return RecordWriter<TModel> */
    abstract protected function writer(): RecordWriter;

    /** @return ListQuery<TModel> */
    abstract protected function listQuery(Request $request): ListQuery;

    /** @param TModel $model */
    abstract protected function present(Model $model): V1Resource;

    public function index(Request $request): JsonResponse
    {
        $page = $this->listQuery($request)->paginate($request);
        $ids = array_map(static fn (Model $m): int => (int) $m->getKey(), $page->items());
        $pending = PendingApprovals::for($this->module, $this->type, $ids);

        return ApiResponse::paginated($page->through(fn (Model $m): V1Resource => $this->present($m)
            ->withPendingApproval(isset($pending[(int) $m->getKey()]))));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success([$this->key => $this->present($this->fresh($this->writer()->find($id)))]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->input($request);
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, $this->module, 'create', $this->type, null, $input,
            fn (): JsonResponse => ApiResponse::created(
                [$this->key => $this->present($this->fresh($this->writer()->create($input, $actor)))],
                $this->messages['created'],
            ));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $model = $this->writer()->find($id);
        $input = $this->input($request);
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, $this->module, $this->updateAction, $this->type, (int) $model->getKey(), $input,
            fn (): JsonResponse => ApiResponse::success(
                [$this->key => $this->present($this->fresh($this->writer()->update($model, $input, $actor)))],
                $this->messages['updated'],
            ));
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $model = $this->writer()->setActive($this->writer()->find($id), $request->input('is_active'), $this->actor($request));

        return ApiResponse::success([$this->key => $this->present($this->fresh($model))], $this->messages['status']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $model = $this->writer()->find($id);
        $actor = $this->actor($request);

        return $this->approvals->guard($actor, $this->module, 'delete', $this->type, (int) $model->getKey(), [],
            function () use ($model, $actor): JsonResponse {
                $this->writer()->delete($model, $actor);

                return ApiResponse::success(null, $this->messages['deleted']);
            });
    }

    public function reorder(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->writer()->reorder($request->input('items'), $this->actor($request)),
            $this->messages['reordered'] ?? 'Order updated successfully',
        );
    }

    /**
     * The request body as the writer reads it (keys already snake_case).
     *
     * @return array<string, mixed>
     */
    protected function input(Request $request): array
    {
        /** @var array<string, mixed> */
        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }

    /**
     * @param  TModel  $model
     * @return TModel
     */
    protected function fresh(Model $model): Model
    {
        return $this->detail === [] ? $model : $model->load($this->detail);
    }

    protected function actor(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }
}
