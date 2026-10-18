<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * An app install's push token (FCM). v1 client_device_tokens.
 *
 * @property int $id
 * @property int $client_id
 * @property string $token
 * @property string $platform
 * @property string|null $device_name
 * @property string|null $app_version
 * @property bool $is_active
 * @property string|null $disabled_reason
 * @property Carbon|null $last_seen_at
 */
final class ClientDevice extends Model
{
    protected $fillable = ['client_id', 'token', 'platform', 'device_name', 'app_version', 'is_active', 'disabled_reason', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }
}
