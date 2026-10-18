<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time 2FA backup code, stored hashed.
 *
 * @property int $id
 * @property int $client_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
final class ClientBackupCode extends Model
{
    protected $fillable = ['client_id', 'code_hash', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
