<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;

/**
 * An event owner's switch for one automatic notice (no row = on).
 *
 * @property int $id
 * @property int $event_id
 * @property int $notification_template_id
 * @property bool $enabled
 */
final class EventTemplatePref extends Model
{
    protected $table = 'event_notification_template_prefs';

    protected $fillable = ['event_id', 'notification_template_id', 'enabled'];

    protected $attributes = ['enabled' => true];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
