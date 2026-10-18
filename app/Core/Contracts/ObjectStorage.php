<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Where uploaded files go (local disk or an S3-compatible bucket, chosen per
 * company in Settings → Media). Implemented by the Platform module.
 *
 * Every method returns the v1 shapes the media screens and the upload fields
 * already read.
 */
interface ObjectStorage
{
    /**
     * Store an upload under <folder>/<unique-name>.
     * When $replacePath is given (crop/replace flow) the new file gets a fresh
     * name next to it and the old one is deleted, so no CDN serves a stale copy.
     *
     * @return array{url: string, path: string, filename: string, size: int, mimetype: string, driver: string}
     */
    public function upload(UploadedFile $file, string $folder = 'uploads', ?string $replacePath = null): array;

    /**
     * Store raw bytes (data URIs, generated images).
     *
     * @return array{url: string, path: string, filename: string, size: int, mimetype: string, driver: string}
     */
    public function put(string $bytes, string $mimetype, string $originalName, string $folder = 'uploads'): array;

    /** Store a `data:<mime>;base64,...` value and return its URL; any other value is returned unchanged. */
    public function storeDataUri(?string $value, string $folder, string $originalName = 'upload'): ?string;

    public function delete(string $path): void;

    /** Delete by a URL this storage produced. False when the URL is not ours. Never throws. */
    public function deleteByUrl(?string $url): bool;
}
