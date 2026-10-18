<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Messaging\Actions\PushConfigs;
use App\Modules\Messaging\Http\Resources\V1\PushConfigResource;
use App\Modules\Messaging\Models\PushConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * /api/v1/push-notification-configs (v1 pushNotificationConfig.controller).
 * The service account arrives as an uploaded `file` or pasted
 * `service_account_json`.
 */
final class PushConfigController
{
    public function __construct(private readonly PushConfigs $configs) {}

    public function index(Request $request): JsonResponse
    {
        $page = (new CallbackListQuery(PushConfig::query(), ['name', 'project_id', 'client_email'], ['created_at', 'name', 'is_active', 'last_verified_at']))
            ->paginate($request);

        return ApiResponse::paginated($page->through(static fn (PushConfig $c): PushConfigResource => new PushConfigResource($c)));
    }

    public function active(): JsonResponse
    {
        $config = $this->configs->active();

        return ApiResponse::success(['pushNotificationConfig' => $config === null ? null : new PushConfigResource($config)]);
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(['pushNotificationConfig' => new PushConfigResource($this->configs->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $config = $this->configs->create($this->input($request), $request->user());

        return ApiResponse::created(['pushNotificationConfig' => new PushConfigResource($config)], 'Push notification configuration created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $config = $this->configs->update($this->configs->find($id), $this->input($request), $request->user());

        return ApiResponse::success(['pushNotificationConfig' => new PushConfigResource($config)], 'Push notification configuration updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->configs->delete($this->configs->find($id));

        return ApiResponse::success(null, 'Push notification configuration deleted successfully');
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        $config = $this->configs->activate($this->configs->find($id), $request->user());

        return ApiResponse::success(['pushNotificationConfig' => new PushConfigResource($config)], 'Project routing status updated successfully');
    }

    public function test(int $id): JsonResponse
    {
        return ApiResponse::success($this->configs->test($this->configs->find($id)));
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $input = $request->except('file');
        $file = $request->file('file');
        if ($file instanceof UploadedFile) {
            $input['service_account_json'] = (string) $file->get();
        }

        return $input;
    }
}
