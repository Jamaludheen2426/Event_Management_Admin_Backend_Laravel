<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * A client's authenticator-app secret (encrypted at rest; v1 stored it plain).
 *
 * @property int $id
 * @property int $client_id
 * @property string $secret
 * @property bool $is_enabled
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $last_used_at
 * @property int|null $last_used_counter
 */
final class ClientTwoFactor extends Model
{
    protected $table = 'client_two_factor';

    protected $fillable = ['client_id', 'secret', 'is_enabled', 'confirmed_at', 'last_used_at', 'last_used_counter'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_enabled' => 'boolean',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_used_counter' => 'integer',
        ];
    }
}
