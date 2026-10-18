<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Support\Carbon;

/**
 * A person in a conversation, with how far they have read.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $actor_type
 * @property int $actor_id
 * @property string|null $display_name
 * @property string|null $avatar
 * @property int|null $last_read_message_id
 * @property Carbon|null $last_read_at
 */
final class ChatParticipant extends Model
{
    protected $fillable = ['conversation_id', 'actor_type', 'actor_id', 'display_name', 'avatar', 'last_read_message_id', 'last_read_at', 'is_active'];

    protected $attributes = ['is_active' => 1];

    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'last_read_message_id' => 'integer', 'last_read_at' => 'datetime', 'is_active' => 'integer'];
    }
}
