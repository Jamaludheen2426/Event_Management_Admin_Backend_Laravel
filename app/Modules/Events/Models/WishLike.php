<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;

/**
 * One client's like on one wish.
 *
 * @property int $id
 * @property int $wish_id
 * @property int $client_id
 */
final class WishLike extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['wish_id', 'client_id'];
}
