<?php

declare(strict_types=1);

namespace App\Modules\Platform\Media;

use Closure;

/**
 * "Is this URL already stored on one of our rows?" — the fallback check of
 * GET /media/proxy, for URLs on a CDN the current storage settings do not
 * know (a database restored from production on a local machine).
 *
 * Modules that own image columns register a check here (Templates registers
 * decorations.file_url, frame_styles.file_url, event_templates.background_image),
 * so Platform does not need to know those tables.
 */
final class KnownUploads
{
    /** @var list<Closure(string): bool> */
    private array $checks = [];

    /** @param Closure(string): bool $check */
    public function register(Closure $check): void
    {
        $this->checks[] = $check;
    }

    public function contains(string $url): bool
    {
        foreach ($this->checks as $check) {
            if ($check($url)) {
                return true;
            }
        }

        return false;
    }
}
