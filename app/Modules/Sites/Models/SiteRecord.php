<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of one of a site's content tables. It carries only its site's id;
 * the site carries the vendor, so every read goes through a Site the caller
 * already owns.
 *
 * @property int $id
 * @property int $site_id
 */
abstract class SiteRecord extends Model
{
    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
