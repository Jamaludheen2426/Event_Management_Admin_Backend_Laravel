<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Gallery and wish media (v1 videoProcess.js + sharp thumbnails).
 *
 *  - Images get a 480px JPEG thumbnail so the app's grid does not download
 *    full photos.
 *  - Videos are made playable everywhere (H.264/yuv420p MP4 or VP8 WebM) and,
 *    on request, muted; a poster frame is taken. All three need ffmpeg
 *    (config media.ffmpeg). Without it the original is kept, exactly as v1
 *    did when its binary was missing — except a requested mute, which then
 *    fails with v1's message rather than storing sound the person asked to
 *    remove.
 */
final class MediaTools
{
    private const THUMB_SIDE = 480;

    private const TIMEOUT_SECONDS = 60;

    /** A JPEG thumbnail of an image, or null when it cannot be made. */
    public function imageThumb(string $bytes): ?string
    {
        try {
            // The shorter side becomes 480px (never enlarged), like sharp's fit "outside".
            $image = (new ImageManager(new Driver))->read($bytes);
            if (min($image->width(), $image->height()) > self::THUMB_SIDE) {
                $image->width() <= $image->height() ? $image->scale(width: self::THUMB_SIDE) : $image->scale(height: self::THUMB_SIDE);
            }

            return (string) $image->toJpeg(72);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{bytes: string, mimetype: string, applied: bool, muted: bool}
     */
    public function processVideo(string $bytes, string $mimetype, bool $mute): array
    {
        $original = ['bytes' => $bytes, 'mimetype' => $mimetype, 'applied' => false, 'muted' => false];
        $webm = $mimetype === 'video/webm';
        [$in, $out] = $this->temp($webm ? 'webm' : 'mp4');

        try {
            file_put_contents($in, $bytes);
            $probe = $this->probe($in);
            if ($probe === null) {
                return $original;
            }
            [$codec, $pixFmt, $audio] = $probe;
            $playable = $webm ? in_array($codec, ['vp8', 'vp9'], true) : ($codec === 'h264' && $pixFmt === 'yuv420p');
            $soundOk = $audio === null || ($webm ? in_array($audio, ['opus', 'vorbis'], true) : in_array($audio, ['aac', 'mp3'], true));
            if ($playable && ! $mute) {
                return $original;
            }
            if ($playable && $audio === null) {
                return [...$original, 'muted' => true];
            }

            $video = $playable ? ['-c:v', 'copy'] : ($webm
                ? ['-c:v', 'libvpx', '-b:v', '600k', '-pix_fmt', 'yuv420p']
                : ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p', '-profile:v', 'main']);
            $sound = $mute || $audio === null ? ['-an'] : ['-map', '0:a:0', ...($soundOk ? ['-c:a', 'copy'] : ($webm ? ['-c:a', 'libopus', '-b:a', '96k'] : ['-c:a', 'aac', '-b:a', '128k']))];
            $this->ffmpeg(['-y', '-i', $in, '-map', '0:v:0', ...$video, ...$sound, ...($webm ? [] : ['-movflags', '+faststart']), $out]);

            $result = is_file($out) ? (string) file_get_contents($out) : '';

            return $result === '' ? $original : ['bytes' => $result, 'mimetype' => $webm ? 'video/webm' : 'video/mp4', 'applied' => true, 'muted' => $mute || $audio === null];
        } catch (Throwable $e) {
            Log::warning('[video] processing skipped, original kept: '.$e->getMessage());

            return $original;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** A JPEG of the first frame (at most 720px wide), or null. */
    public function videoPoster(string $bytes): ?string
    {
        [$in, $out] = $this->temp('jpg');
        try {
            file_put_contents($in, $bytes);
            $this->ffmpeg(['-y', '-i', $in, '-frames:v', '1', '-vf', "scale='min(720,iw)':-2", '-q:v', '4', $out]);
            $poster = is_file($out) ? (string) file_get_contents($out) : '';

            return $poster === '' ? null : $poster;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null}|null codec, pixel format, audio codec; null without ffmpeg */
    private function probe(string $file): ?array
    {
        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([$this->binary(), '-hide_banner', '-i', $file]);
        } catch (Throwable) {
            return null;
        }
        $err = $result->errorOutput();
        if (! str_contains($err, 'Input #0')) {
            return null;
        }
        preg_match('/Video: (\w+)[^\n]*?, (yuv\w+|nv12|gbrp|rgb\w*)/', $err, $v);
        preg_match('/Audio: (\w+)/', $err, $a);

        return [$v[1] ?? null, $v[2] ?? null, $a[1] ?? null];
    }

    /** @param list<string> $args */
    private function ffmpeg(array $args): void
    {
        Process::timeout(self::TIMEOUT_SECONDS)->run([$this->binary(), ...$args])->throw();
    }

    private function binary(): string
    {
        return (string) config('media.ffmpeg', 'ffmpeg');
    }

    /** @return array{0: string, 1: string} */
    private function temp(string $ext): array
    {
        $id = bin2hex(random_bytes(8));
        $dir = sys_get_temp_dir();

        return ["{$dir}/vp-{$id}-in", "{$dir}/vp-{$id}-out.{$ext}"];
    }
}
