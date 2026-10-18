<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\Wishes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Wishes on an event (v1 clientWish.controller).
 */
final class WishController
{
    public function __construct(private readonly Wishes $wishes) {}

    public function index(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->wishes->list($this->client($request), $id), 'Wishes loaded.');
    }

    public function show(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success(['wish' => $this->wishes->show($this->client($request), $wishId)], 'Wish loaded.');
    }

    public function store(Request $request, int $id): JsonResponse
    {
        return ApiResponse::created(['wish' => $this->wishes->create($this->client($request), $id, $request->all(), $this->file($request))], 'Your post is published.');
    }

    public function update(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success(['wish' => $this->wishes->update($this->client($request), $wishId, $request->all())], 'Wish updated.');
    }

    public function media(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success(['wish' => $this->wishes->replaceMedia($this->client($request), $wishId, $this->file($request), $request->all())], 'Wish updated.');
    }

    public function destroy(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success($this->wishes->delete($this->client($request), $wishId), 'Wish deleted.');
    }

    public function like(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success(['wish' => $this->wishes->like($this->client($request), $wishId, true)], 'Liked.');
    }

    public function unlike(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success(['wish' => $this->wishes->like($this->client($request), $wishId, false)], 'Like removed.');
    }

    public function block(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success($this->wishes->block($this->client($request), $wishId, true, $request->boolean('remove_wishes')), 'User blocked from wishes.');
    }

    public function unblock(Request $request, int $wishId): JsonResponse
    {
        return ApiResponse::success($this->wishes->block($this->client($request), $wishId, false, false), 'User unblocked.');
    }

    public function settings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->wishes->viewSettings($this->client($request), $id), 'Wish settings loaded.');
    }

    public function updateSettings(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->wishes->updateSettings($this->client($request), $id, $request->all()), 'Wish settings saved.');
    }

    public function filter(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->wishes->viewFilter($this->client($request), $id), 'Content filter loaded.');
    }

    public function updateFilter(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->wishes->updateFilter($this->client($request), $id, $request->all()), 'Content filter saved.');
    }

    private function file(Request $request): ?UploadedFile
    {
        $file = $request->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
