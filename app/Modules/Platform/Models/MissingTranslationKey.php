<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * A UI key a frontend asked for that does not exist yet.
 *
 * @property int $id
 * @property string $key
 * @property int $report_count
 * @property Carbon|null $resolved_at
 */
final class MissingTranslationKey extends Model
{
    protected $fillable = ['key', 'default_value', 'page_url', 'report_count', 'first_reported_at', 'last_reported_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'report_count' => 'integer',
            'first_reported_at' => 'datetime',
            'last_reported_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
