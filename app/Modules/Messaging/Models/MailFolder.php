<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person's own mail folder (at most 10).
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $name
 * @property int $is_active
 */
final class MailFolder extends Model
{
    use SoftDeletes;

    protected $fillable = ['owner_type', 'owner_id', 'name', 'is_active'];

    protected $attributes = ['is_active' => 1];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'is_active' => 'integer'];
    }
}
