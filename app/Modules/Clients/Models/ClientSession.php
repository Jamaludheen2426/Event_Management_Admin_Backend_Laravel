<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * One sign-in of a client: the device it came from and whether it is still
 * live. Its tokens carry session_id in personal_access_tokens.
 *
 * @property int $id
 * @property int $client_id
 * @property string $transport
 * @property string|null $device_name
 * @property string|null $device_type
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $location
 * @property Carbon|null $last_active_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $trusted_until
 * @property Carbon|null $created_at
 */
final class ClientSession extends Model
{
    protected $fillable = [
        'client_id', 'transport', 'device_name', 'device_type', 'browser', 'os', 'ip_address', 'user_agent',
        'location', 'last_active_at', 'expires_at', 'revoked_at', 'revoked_reason', 'trusted_until',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'trusted_until' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
