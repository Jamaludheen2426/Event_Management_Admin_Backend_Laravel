<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;

/**
 * One notification switch a client changed (unset types use the catalogue default).
 *
 * @property int $id
 * @property int $client_id
 * @property string $channel
 * @property string $type
 * @property bool $enabled
 * @property string|null $frequency
 * @property bool|null $sound
 */
final class ClientNotificationPref extends Model
{
    protected $fillable = ['client_id', 'channel', 'type', 'enabled', 'frequency', 'sound'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sound' => 'boolean'];
    }
}
