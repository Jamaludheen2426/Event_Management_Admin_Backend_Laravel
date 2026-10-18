<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A photo or video in an event's gallery.
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $category_id
 * @property string $type
 * @property string $url
 * @property string|null $thumbnail_url
 * @property string|null $file_name
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string|null $caption
 * @property bool $show_in_app
 * @property int $sort_order
 * @property Carbon|null $created_at
 */
final class GalleryItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['event_id', 'category_id', 'type', 'url', 'thumbnail_url', 'file_name', 'mime_type', 'size_bytes', 'caption', 'show_in_app', 'sort_order', 'uploaded_by'];

    protected $attributes = ['show_in_app' => true, 'sort_order' => 0, 'size_bytes' => 0];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'show_in_app' => 'boolean', 'sort_order' => 'integer'];
    }
}
