<?php

declare(strict_types=1);

namespace App\Modules\Templates\Support;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Modules\Platform\Media\MediaProxy;
use Throwable;

/**
 * Recolouring SVG artwork (frame styles and decorations): read the SVG, list
 * its hex colours by frequency, swap colours, and store the result as a new
 * file (the original stays — templates may still use it).
 *
 * Reading goes through the media proxy, so only our own uploads (or a URL on
 * one of our rows) can ever be fetched — the same SSRF guard as v1.
 */
final class SvgArtwork
{
    private const HEX = '/#[0-9a-fA-F]{3,8}\b/';

    public function __construct(
        private readonly MediaProxy $proxy,
        private readonly ObjectStorage $storage,
    ) {}

    /**
     * @param  string  $noun  "decoration" / "frame style", for messages
     * @return array{svg: string, colors: list<array{color: string, count: int}>}
     */
    public function source(?string $url, string $noun): array
    {
        $svg = $this->read($url, $noun);

        return ['svg' => $svg, 'colors' => self::palette($svg)];
    }

    /**
     * @param  mixed  $colorMap  [{from, to}] or {from: to}
     * @return array<string, mixed>
     */
    public function recolor(?string $url, mixed $colorMap, ?string $fileName, string $noun, string $folder): array
    {
        $pairs = self::pairs($colorMap);
        if ($pairs === []) {
            throw ApiException::badRequest('Pick at least one colour to change.');
        }
        $lookup = [];
        foreach ($pairs as [$from, $to]) {
            $source = self::hex($from);
            $target = self::hex($to);
            if ($source === null || $target === null) {
                throw ApiException::badRequest('"'.self::text($from).'" → "'.self::text($to).'" is not a pair of hex colours.');
            }
            $lookup[$source] = $target;
        }

        $changed = 0;
        $svg = (string) preg_replace_callback(self::HEX, static function (array $m) use ($lookup, &$changed): string {
            $hex = self::hex($m[0]);
            if ($hex !== null && isset($lookup[$hex])) {
                $changed++;

                return $lookup[$hex];
            }

            return $m[0];
        }, $this->read($url, $noun));

        if ($changed === 0) {
            throw ApiException::badRequest("None of those colours appear in this {$noun}.");
        }

        $base = (string) preg_replace('/-recoloured(-\d+)?$/i', '', (string) preg_replace('/\.[^.]+$/', '', (string) ($fileName ?: $noun)));
        $base = mb_substr($base, 0, 100) ?: 'artwork';
        $name = "{$base}-recoloured.svg";
        $stored = $this->storage->put($svg, 'image/svg+xml', $name, $folder);

        return [
            'url' => $stored['url'],
            'file_name' => $name,
            'file_format' => 'SVG',
            'file_size' => strlen($svg),
            'file_size_label' => self::size(strlen($svg)),
            'replaced' => $changed,
            'colors' => self::palette($svg),
        ];
    }

    /** v1 formatSize(): "245 KB". */
    public static function size(?int $bytes): ?string
    {
        if ($bytes === null || $bytes <= 0) {
            return null;
        }
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return number_format($bytes / (1024 * 1024), 1).' MB';
    }

    private function read(?string $url, string $noun): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            throw ApiException::badRequest("A {$noun} file URL is required.");
        }

        try {
            ['dataUri' => $dataUri] = $this->proxy->read($url);
        } catch (ApiException $e) {
            throw $e->status() === 404
                ? ApiException::notFound("That {$noun} file no longer exists.")
                : ApiException::badRequest("That file is not a {$noun} upload.");
        } catch (Throwable $e) {
            report($e);
            throw ApiException::badRequest("That file is not a {$noun} upload.");
        }

        $svg = (string) base64_decode(substr($dataUri, (int) strpos($dataUri, ',') + 1), true);
        if (! str_contains($svg, '<svg')) {
            throw ApiException::badRequest('Only SVG '.$noun.'s can be recoloured.');
        }

        return $svg;
    }

    /** @return list<array{color: string, count: int}> most used first */
    private static function palette(string $svg): array
    {
        preg_match_all(self::HEX, $svg, $matches);
        $counts = [];
        foreach ($matches[0] as $token) {
            $hex = self::hex($token);
            if ($hex !== null) {
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_map(static fn (string $color, int $count): array => ['color' => $color, 'count' => $count], array_keys($counts), array_values($counts));
    }

    /** #abc -> #AABBCC; 4/6/8 digit forms kept; anything else null. */
    private static function hex(mixed $value): ?string
    {
        $body = ltrim(trim(self::text($value)), '#');
        if (! preg_match('/^[0-9a-fA-F]+$/', $body)) {
            return null;
        }
        if (strlen($body) === 3 || strlen($body) === 4) {
            $body = implode('', array_map(static fn (string $c): string => $c.$c, str_split($body)));
        }

        return in_array(strlen($body), [6, 8], true) ? '#'.strtoupper($body) : null;
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    private static function pairs(mixed $map): array
    {
        if (! is_array($map)) {
            return [];
        }
        if (array_is_list($map)) {
            return array_values(array_map(
                static fn (array $p): array => [$p['from'] ?? null, $p['to'] ?? null],
                array_filter($map, 'is_array'),
            ));
        }

        return array_map(static fn ($from, $to): array => [$from, $to], array_keys($map), array_values($map));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
