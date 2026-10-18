<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Model;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A ready-made event notification: title and content with {{variables}}.
 * trigger_key marks the one template a system event sends.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $trigger_key
 * @property string $title
 * @property string $content
 * @property list<string> $channels
 */
final class NotificationTemplate extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'trigger_key', 'notification_category_id', 'event_category_id', 'title', 'content',
        'variables_used', 'image_url', 'channels', 'sort_order', 'is_active', 'approval_status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['variables_used' => 'array', 'channels' => 'array'];
    }

    /** @return BelongsTo<NotificationCategory, $this> */
    public function notificationCategory(): BelongsTo
    {
        return $this->belongsTo(NotificationCategory::class);
    }

    /** @return BelongsTo<EventCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }
}
