<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A wish posted on an event: text, a photo or a short video.
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $author_client_id
 * @property string $post_type
 * @property string $audience
 * @property string $guest_name
 * @property string|null $mobile
 * @property string $message
 * @property string|null $photo
 * @property string|null $video_url
 * @property string|null $thumbnail_url
 * @property int|null $duration_seconds
 * @property int $size_bytes
 * @property bool $is_hidden
 * @property bool $is_flagged
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Wish extends Model
{
    protected $fillable = [
        'event_id', 'author_client_id', 'post_type', 'audience', 'guest_name', 'mobile', 'message', 'photo', 'video_url',
        'thumbnail_url', 'duration_seconds', 'size_bytes', 'is_hidden', 'is_flagged',
    ];

    protected $attributes = ['post_type' => 'text', 'audience' => 'both', 'size_bytes' => 0, 'is_hidden' => false, 'is_flagged' => false];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'duration_seconds' => 'integer', 'is_hidden' => 'boolean', 'is_flagged' => 'boolean'];
    }

    /** @return HasMany<WishLike, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(WishLike::class);
    }
}
