<?php

declare(strict_types=1);

namespace App\Modules\Clients\Models;

use App\Core\Database\Model;

/**
 * A template code or event the client starred (v1 favourite_* JSON columns).
 *
 * @property int $id
 * @property int $client_id
 * @property string $kind
 * @property string $ref
 * @property int $sort_order
 */
final class ClientFavourite extends Model
{
    protected $fillable = ['client_id', 'kind', 'ref', 'sort_order'];
}
