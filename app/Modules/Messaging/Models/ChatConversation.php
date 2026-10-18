<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Core\Database\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A chat between two people (v1 only ever made direct ones).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $vendor_id
 * @property string $conversation_type
 * @property string|null $title
 * @property int|null $last_message_id
 * @property Carbon|null $last_message_at
 * @property-read Collection<int, ChatParticipant> $participants
 * @property-read ChatMessage|null $lastMessage
 */
final class ChatConversation extends Model
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'vendor_id', 'conversation_type', 'context_type', 'title', 'last_message_id', 'last_message_at', 'is_active'];

    protected $attributes = ['conversation_type' => 'direct', 'context_type' => 'general', 'is_active' => 1];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'is_active' => 'integer'];
    }

    /** @return HasMany<ChatParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }
}
