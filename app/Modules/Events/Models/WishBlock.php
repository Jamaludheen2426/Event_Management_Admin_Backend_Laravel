<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;

/**
 * An author the host blocked from posting wishes on one event; with hide_menu
 * the Wishes tile disappears for them too.
 *
 * @property int $id
 * @property int $event_id
 * @property int $author_client_id
 * @property bool $hide_menu
 */
final class WishBlock extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'author_client_id', 'hide_menu'];

    protected function casts(): array
    {
        return ['hide_menu' => 'boolean'];
    }
}
