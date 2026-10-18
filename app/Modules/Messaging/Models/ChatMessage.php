<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $sender_type
 * @property int $sender_id
 * @property string $message
 * @property string $message_type
 * @property array<string, mixed>|null $metadata
 */
final class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['conversation_id', 'sender_type', 'sender_id', 'message', 'message_type', 'metadata'];

    protected $attributes = ['message_type' => 'text'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'sender_id' => 'integer'];
    }
}
