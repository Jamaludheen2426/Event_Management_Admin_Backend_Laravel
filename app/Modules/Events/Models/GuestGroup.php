<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group in a client's phone book (Family, Friends, Colleagues).
 *
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string|null $description
 * @property string|null $color
 * @property string $visibility
 * @property bool $is_default
 */
final class GuestGroup extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'name', 'description', 'color', 'visibility', 'is_default'];

    protected $attributes = ['color' => '#EC4899', 'visibility' => 'private', 'is_default' => false];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return HasMany<Guest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class, 'group_id');
    }
}
