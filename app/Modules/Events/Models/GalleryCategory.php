<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An album of an event's gallery. Every event has one default album,
 * "Gallery", which cannot be renamed or deleted.
 *
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property string|null $description
 * @property string|null $cover_image
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_default
 */
final class GalleryCategory extends Model
{
    use SoftDeletes;

    protected $fillable = ['event_id', 'name', 'description', 'cover_image', 'icon', 'sort_order', 'is_default'];

    protected $attributes = ['sort_order' => 0, 'is_default' => false];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_default' => 'boolean'];
    }
}
