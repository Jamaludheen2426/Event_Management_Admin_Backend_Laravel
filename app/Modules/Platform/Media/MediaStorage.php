<?php

declare(strict_types=1);

namespace App\Modules\Platform\Media;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploads for every module, on the company's configured storage.
 *
 * One Laravel filesystem disk is built from the company's media settings:
 * the local disk (public/uploads, served as APP_URL/uploads/...) or any
 * S3-compatible bucket. `custom_s3_path` is the disk root, so every key and
 * URL carries it, as in v1.
 *
 * Paths given by callers are always relative to that root and are checked:
 * no "..", no absolute paths (v1's copy/move/rename accepted both).
 */
final class MediaStorage implements ObjectStorage
{
    private const EXTENSION_MIMES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    ];

    private const COMPRESSIBLE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Default endpoints for S3-compatible providers when none is configured (v1 list). */
    private const PROVIDER_ENDPOINTS = [
        'r2' => 'https://{account}.r2.cloudflarestorage.com',
        'digitalocean' => 'https://{region}.digitaloceanspaces.com',
        'wasabi' => 'https://s3.{region}.wasabisys.com',
        'backblaze' => 'https://s3.{region}.backblazeb2.com',
        'bunnycdn' => 'https://storage.bunnycdn.com',
    ];

    public function __construct(private readonly MediaSettings $settings) {}

    public function upload(UploadedFile $file, string $folder = 'uploads', ?string $replacePath = null): array
    {
        $bytes = (string) file_get_contents($file->getRealPath() ?: '');
        $mime = (string) ($file->getMimeType() ?? $file->getClientMimeType());

        if ($replacePath !== null) {
            // Crop / replace: a fresh name beside the old file, so no CDN ever
            // serves the stale copy; then the old file goes.
            $replacePath = $this->safePath($replacePath);
            $directory = dirname($replacePath);
            $result = $this->store($bytes, $mime, basename($replacePath), $directory === '.' ? '' : $directory);
            $this->deleteQuietly($replacePath);

            return $result;
        }

        return $this->store($bytes, $mime, $file->getClientOriginalName(), $folder);
    }

    public function put(string $bytes, string $mimetype, string $originalName, string $folder = 'uploads'): array
    {
        return $this->store($bytes, $mimetype, $originalName, $folder);
    }

    public function storeDataUri(?string $value, string $folder, string $originalName = 'upload'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^data:([^;]+);base64,(.+)$/s', $value, $match) !== 1) {
            return $value;
        }

        $bytes = base64_decode($match[2], true);
        if ($bytes === false) {
            throw ApiException::badRequest('The uploaded image could not be read.');
        }
        $extension = str_replace('jpeg', 'jpg', explode('/', $match[1])[1] ?? 'bin');

        return $this->store($bytes, $match[1], "{$originalName}.{$extension}", $folder)['url'];
    }

    public function delete(string $path): void
    {
        try {
            $this->disk()->delete($this->safePath($path));
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('File deletion failed: '.$e->getMessage(), 500);
        }
    }

    public function deleteByUrl(?string $url): bool
    {
        try {
            $path = $url === null ? null : $this->pathFromUrl($url);
            if ($path === null) {
                return false;
            }
            $this->disk()->delete($path);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Media library listing (v1 shape).
     *
     * @return array{folders: list<array<string, string>>, files: list<array<string, mixed>>, path: string, driver: string}
     */
    public function list(string $folder): array
    {
        $folder = $folder === '' ? '' : $this->safePath($folder);
        $disk = $this->disk();

        $folders = array_map(static fn (string $dir): array => [
            'name' => basename($dir), 'path' => $dir, 'type' => 'folder',
        ], $disk->directories($folder));

        $files = [];
        foreach ($disk->files($folder) as $path) {
            $modified = $this->isoTime($disk->lastModified($path));
            $files[] = [
                'name' => basename($path),
                'path' => $path,
                'url' => $disk->url($path),
                'size' => $disk->size($path),
                'mimetype' => $this->mimeFor($path),
                'type' => 'file',
                'created_at' => $modified,
                'updated_at' => $modified,
            ];
        }

        return ['folders' => array_values($folders), 'files' => $files, 'path' => $folder, 'driver' => $this->driver()];
    }

    /** @return array{path: string, name: string} */
    public function makeFolder(string $folder): array
    {
        $folder = $this->safePath($folder);
        $this->disk()->makeDirectory($folder);

        return ['path' => $folder, 'name' => basename($folder)];
    }

    /** @return array{path: string, name: string, url: string} */
    public function copy(string $source, string $targetFolder, string $newName): array
    {
        $source = $this->safePath($source);
        $target = $this->target($targetFolder, $newName);
        $disk = $this->disk();

        if (! $disk->exists($source)) {
            throw ApiException::notFound('Source file does not exist');
        }
        $disk->copy($source, $target);

        return ['path' => $target, 'name' => basename($target), 'url' => $disk->url($target)];
    }

    /** @return array{path: string, name: string, url: string} */
    public function move(string $source, string $targetFolder, ?string $newName = null): array
    {
        $result = $this->copy($source, $targetFolder, $newName ?? basename($source));
        $this->disk()->delete($this->safePath($source));

        return $result;
    }

    /** @return array{path: string, name: string, url: string} */
    public function rename(string $path, string $newName): array
    {
        $directory = dirname($this->safePath($path));

        return $this->move($path, $directory === '.' ? '' : $directory, $newName);
    }

    /** The disk's own file for a URL this storage produced, or null. */
    public function pathFromUrl(string $url): ?string
    {
        $url = strtok($url, '?') ?: $url;
        $base = rtrim($this->disk()->url(''), '/').'/';

        if (str_starts_with($url, $base)) {
            $relative = rawurldecode(substr($url, strlen($base)));
        } elseif ($this->driver() === 'local' && preg_match('#/uploads/(.+)$#', $url, $m) === 1) {
            $relative = rawurldecode($m[1]);
        } else {
            return null;
        }

        return str_contains($relative, '..') || $relative === '' ? null : $relative;
    }

    /** Bytes of one of our files, by relative path. */
    public function read(string $path): ?string
    {
        $disk = $this->disk();
        $path = $this->safePath($path);

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function driver(): string
    {
        return (string) ($this->settings->forCurrentCompany()['driver'] ?? 'local') ?: 'local';
    }

    /** Base URL every remote upload of this company starts with (null for local). */
    public function remoteBase(): ?string
    {
        return $this->driver() === 'local' ? null : rtrim($this->disk()->url(''), '/');
    }

    public function disk(): Filesystem
    {
        $config = $this->settings->forCurrentCompany();
        $driver = $this->driver();

        if ($driver === 'local') {
            return Storage::build([
                'driver' => 'local',
                'root' => config('media.local_root'),
                'url' => config('media.local_url'),
                'visibility' => 'public',
                'throw' => true,
            ]);
        }

        $region = $config['aws_region'] ?? null ?: 'us-east-1';
        $endpoint = $config['aws_endpoint'] ?? null;
        if (($endpoint === null || $endpoint === '') && isset(self::PROVIDER_ENDPOINTS[$driver])) {
            $endpoint = strtr(self::PROVIDER_ENDPOINTS[$driver], ['{account}' => (string) ($config['aws_account_id'] ?? ''), '{region}' => $region]);
        }

        return Storage::build(array_filter([
            'driver' => 's3',
            'key' => $config['aws_access_key'] ?? '',
            'secret' => $config['aws_secret_key'] ?? '',
            'region' => $region,
            'bucket' => $config['aws_bucket'] ?? null ?: throw ApiException::badRequest('Storage bucket is not configured'),
            'url' => isset($config['aws_url']) && $config['aws_url'] !== '' ? rtrim($config['aws_url'], '/') : null,
            'endpoint' => $endpoint !== '' ? $endpoint : null,
            'use_path_style_endpoint' => ($config['use_path_style_endpoint'] ?? '') === 'yes',
            'root' => isset($config['custom_s3_path']) && $config['custom_s3_path'] !== '' ? trim($config['custom_s3_path'], '/') : null,
            'throw' => true,
        ], static fn (mixed $v): bool => $v !== null));
    }

    // ---------------------------------------------------------------------

    /** @return array{url: string, path: string, filename: string, size: int, mimetype: string, driver: string} */
    private function store(string $bytes, string $mime, string $originalName, string $folder): array
    {
        $bytes = $this->compress($bytes, $mime);
        $folder = trim($folder, '/');
        $path = ($folder === '' ? '' : $this->safePath($folder).'/').$this->uniqueName($originalName);

        try {
            $disk = $this->disk();
            $disk->put($path, $bytes, ['visibility' => 'public', 'ContentType' => $mime]);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('File upload failed: '.$e->getMessage(), 500);
        }

        return [
            'url' => $disk->url($path),
            'path' => $path,
            'filename' => basename($path),
            'size' => strlen($bytes),
            'mimetype' => $mime,
            'driver' => $this->driver(),
        ];
    }

    /** v1 generateFilename(): "my-photo-<ms>-<random6>.jpg". */
    private function uniqueName(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $name = (string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9]/', '-', strtolower(pathinfo($originalName, PATHINFO_FILENAME))));

        return $name.'-'.(int) floor(microtime(true) * 1000).'-'.Str::lower(Str::random(6)).($extension !== '' ? ".{$extension}" : '');
    }

    /** Re-encode images at the company's quality setting (Settings -> Optimize). Never fails the upload. */
    private function compress(string $bytes, string $mime): string
    {
        if (! in_array($mime, self::COMPRESSIBLE, true) || ! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        ['enabled' => $enabled, 'quality' => $quality] = $this->settings->compression();
        if (! $enabled) {
            return $bytes;
        }

        try {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return $bytes;
            }
            ob_start();
            match ($mime) {
                'image/png' => imagepng($image, null, (int) round((100 - $quality) * 9 / 100)),
                'image/webp' => imagewebp($image, null, $quality),
                default => imagejpeg($image, null, $quality),
            };
            $out = (string) ob_get_clean();
            imagedestroy($image);

            return $out !== '' && strlen($out) < strlen($bytes) ? $out : $bytes;
        } catch (Throwable $e) {
            report($e);

            return $bytes;
        }
    }

    private function target(string $folder, string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw ApiException::badRequest('Invalid file name');
        }
        $folder = trim($folder, '/');

        return $folder === '' ? $name : $this->safePath($folder).'/'.$name;
    }

    private function safePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            throw ApiException::badRequest('That file path is not allowed.');
        }

        return $path;
    }

    private function deleteQuietly(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function mimeFor(string $path): string
    {
        return self::EXTENSION_MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    private function isoTime(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $timestamp);
    }
}
