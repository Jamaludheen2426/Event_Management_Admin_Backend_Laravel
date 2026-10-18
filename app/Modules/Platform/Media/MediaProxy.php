<?php

declare(strict_types=1);

namespace App\Modules\Platform\Media;

use App\Core\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

/**
 * GET /media/proxy?url= — one of OUR uploaded images as a data URI, for canvas
 * exports (the bucket sends no CORS header, so a canvas that drew it cannot be
 * read back).
 *
 * SSRF guard as in v1: local uploads by path; remote only when the URL starts
 * with the configured storage base, or is already stored on one of our rows,
 * and never an internal host.
 */
final class MediaProxy
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly KnownUploads $known,
    ) {}

    /** @return array{dataUri: string, contentType: string} */
    public function read(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw ApiException::badRequest('A file URL is required.');
        }

        if ($this->storage->driver() === 'local' || str_starts_with($url, '/uploads/')) {
            $path = $this->storage->pathFromUrl($url);
            if ($path !== null) {
                $bytes = $this->storage->read($path) ?? throw ApiException::notFound('That file no longer exists.');
                $mime = $this->mimeFromPath($path);

                return ['dataUri' => "data:{$mime};base64,".base64_encode($bytes), 'contentType' => $mime];
            }
        }

        $parts = parse_url($url);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $this->isInternalHost((string) ($parts['host'] ?? ''))) {
            throw ApiException::badRequest('That file is not one of our uploads.');
        }

        $base = $this->storage->remoteBase();
        $ours = $base !== null && str_starts_with($url, $base.'/');
        if (! $ours && ! $this->known->contains($url)) {
            throw ApiException::badRequest('That file is not one of our uploads.');
        }

        $response = Http::timeout(15)->withOptions(['allow_redirects' => false])->get($url);
        if (! $response->successful()) {
            throw ApiException::notFound('That file no longer exists.');
        }
        $body = $response->body();
        if (strlen($body) > (int) config('media.proxy_max_bytes')) {
            throw ApiException::badRequest('That file is too large.');
        }
        $type = $response->header('Content-Type') ?: 'application/octet-stream';

        return ['dataUri' => "data:{$type};base64,".base64_encode($body), 'contentType' => $type];
    }

    private function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    private function isInternalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return true;
        }
        if ($host === '::1' || $host === '::' || str_starts_with($host, 'fc') || str_starts_with($host, 'fd')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}
