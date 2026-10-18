<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Platform\Media\MediaProxy;
use App\Modules\Platform\Media\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * /api/v1/media — the media library and raw uploads.
 *
 * v1 put uploads and deletes behind the approval workflow, but its approval
 * step could never run them (the handler was a no-op), so approving did
 * nothing. They are direct actions now, gated by media.upload / media.delete.
 */
final class MediaController
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly ActivityLogger $activity,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => $this->fileRule('required')], $this->fileMessages('file'));
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $result = $this->storage->upload($file, $this->folder($request), $this->optionalString($request, 'path'));
        $this->activity->log('media', 'upload', "Uploaded {$result['path']}");

        return ApiResponse::success(['file' => $result], 'File uploaded successfully');
    }

    public function uploadMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:'.config('media.max_files')],
            'files.*' => $this->fileRule('required'),
        ], ['files.required' => 'No files provided', ...$this->fileMessages('files.*')]);

        $results = [];
        foreach ((array) $request->file('files') as $file) {
            $results[] = $this->storage->upload($file, $this->folder($request));
        }
        $this->activity->log('media', 'upload', count($results).' files uploaded');

        return ApiResponse::success(['files' => $results], 'Files uploaded successfully');
    }

    public function destroy(Request $request): JsonResponse
    {
        $path = $this->optionalString($request, 'path') ?? throw ApiException::badRequest('File path is required');
        $this->storage->delete($path);
        $this->activity->log('media', 'delete', "Deleted {$path}");

        return ApiResponse::success(null, 'File deleted successfully');
    }

    public function files(Request $request): JsonResponse
    {
        return ApiResponse::success($this->storage->list((string) $request->query('folder', '')), 'Files listed successfully');
    }

    public function createFolder(Request $request): JsonResponse
    {
        $folder = $this->optionalString($request, 'folder') ?? throw ApiException::badRequest('Folder path is required');

        return ApiResponse::success($this->storage->makeFolder($folder), 'Folder created successfully');
    }

    public function rename(Request $request): JsonResponse
    {
        $path = $this->optionalString($request, 'path');
        $name = $this->optionalString($request, 'new_name');
        if ($path === null || $name === null) {
            throw ApiException::badRequest('Path and newName are required');
        }

        return ApiResponse::success($this->storage->rename($path, $name), 'File renamed successfully');
    }

    public function copy(Request $request): JsonResponse
    {
        $path = $this->optionalString($request, 'path');
        $name = $this->optionalString($request, 'new_name');
        if ($path === null || $name === null) {
            throw ApiException::badRequest('sourcePath and newName are required');
        }

        return ApiResponse::success($this->storage->copy($path, (string) $request->input('target_folder', ''), $name), 'File copied successfully');
    }

    public function move(Request $request): JsonResponse
    {
        $path = $this->optionalString($request, 'path');
        if ($path === null || ! $request->has('target_folder')) {
            throw ApiException::badRequest('sourcePath and targetFolder are required');
        }

        return ApiResponse::success($this->storage->move($path, (string) $request->input('target_folder', '')), 'File moved successfully');
    }

    public function proxy(Request $request, MediaProxy $proxy): JsonResponse
    {
        return ApiResponse::success($proxy->read((string) $request->query('url', '')));
    }

    /** @return list<string> */
    private function fileRule(string $presence): array
    {
        return [$presence, 'file', 'max:'.config('media.max_kilobytes'), 'mimetypes:'.implode(',', (array) config('media.allowed_mimes'))];
    }

    /** @return array<string, string> */
    private function fileMessages(string $field): array
    {
        return [
            "{$field}.required" => 'No file provided',
            "{$field}.max" => 'File size exceeds the 10MB limit.',
            "{$field}.mimetypes" => 'This file type is not allowed',
        ];
    }

    private function folder(Request $request): string
    {
        return $this->optionalString($request, 'folder') ?? 'uploads';
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
