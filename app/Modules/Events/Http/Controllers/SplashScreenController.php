<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Core\Queries\CallbackListQuery;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\SplashScreens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * /api/v1/client/splash-screens/* (v1 clientSplashScreen.controller).
 */
final class SplashScreenController
{
    public function __construct(private readonly SplashScreens $splashes) {}

    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', '12')));
        $page = (new CallbackListQuery($this->splashes->query($this->client($request))->orderByDesc('updated_at'), ['name', 'main_title', 'event_name'], ['updated_at']))
            ->paginate($request->duplicate([...$request->query(), 'limit' => $limit]));

        return ApiResponse::paginated($page, message: 'Splash screens retrieved');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(['splash_screen' => $this->splashes->find($this->client($request), $id)], 'Splash screen retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::created(['splash_screen' => $this->splashes->create($this->client($request), $request->all())], 'Splash screen created successfully');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->client($request);
        $splash = $this->splashes->update($client, $this->splashes->find($client, $id), $request->all());

        return ApiResponse::success(['splash_screen' => $splash], 'Splash screen updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->splashes->delete($this->splashes->find($this->client($request), $id));

        return ApiResponse::success(['deleted' => true], 'Splash screen deleted');
    }

    public function forEvent(Request $request, int $eventId): JsonResponse
    {
        return ApiResponse::success(['splash_screen' => $this->splashes->forEvent($this->client($request), $eventId)], 'Splash screen retrieved');
    }

    public function decorations(Request $request): JsonResponse
    {
        return ApiResponse::success($this->splashes->decorations($this->client($request)), 'Decorations retrieved');
    }

    public function media(Request $request): JsonResponse
    {
        $file = $request->file('file');

        return ApiResponse::success($this->splashes->upload($file instanceof UploadedFile ? $file : null), 'File uploaded');
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
