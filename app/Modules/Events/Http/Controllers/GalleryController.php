<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Core\Http\ApiResponse;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Actions\Gallery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * An event's gallery and albums (v1 clientGallery.controller).
 */
final class GalleryController
{
    public function __construct(private readonly Gallery $gallery) {}

    public function index(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->gallery->list($this->client($request), $id, $request->only(['type', 'category_id'])), 'Gallery loaded.');
    }

    public function usage(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->gallery->usage($this->client($request), $id), 'Gallery usage loaded.');
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->gallery->upload($this->client($request), $id, $this->file($request), $request->all()), 'Uploaded.');
    }

    public function update(Request $request, int $itemId): JsonResponse
    {
        return ApiResponse::success($this->gallery->update($this->client($request), $itemId, $request->all()), 'Updated.');
    }

    public function destroy(Request $request, int $itemId): JsonResponse
    {
        return ApiResponse::success($this->gallery->delete($this->client($request), $itemId), 'Removed.');
    }

    public function reorder(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->gallery->reorder($this->client($request), $id, $request->input('ids')), 'Gallery order saved.');
    }

    public function categories(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(['categories' => $this->gallery->categories($this->client($request), $id)], 'Categories loaded.');
    }

    public function addCategory(Request $request, int $id): JsonResponse
    {
        return ApiResponse::created(['category' => $this->gallery->createAlbum($this->client($request), $id, $request->all())], 'Category added.');
    }

    public function updateCategory(Request $request, int $categoryId): JsonResponse
    {
        return ApiResponse::success($this->gallery->updateAlbum($this->client($request), $categoryId, $request->all()), 'Category updated.');
    }

    public function deleteCategory(Request $request, int $categoryId): JsonResponse
    {
        return ApiResponse::success($this->gallery->deleteAlbum($this->client($request), $categoryId), 'Category removed.');
    }

    public function categoryCover(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->gallery->uploadCover($this->client($request), $id, $this->file($request)), 'Image uploaded.');
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
