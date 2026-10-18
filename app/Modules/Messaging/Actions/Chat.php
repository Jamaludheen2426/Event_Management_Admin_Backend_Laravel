<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Dates;
use App\Modules\Messaging\Models\ChatConversation;
use App\Modules\Messaging\Models\ChatMessage;
use App\Modules\Messaging\Models\ChatParticipant;
use App\Modules\Messaging\Support\Actor;
use App\Modules\Messaging\Support\ChatRelay;
use App\Modules\Messaging\Support\People;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Chat between admins, vendors and clients (v1 chat.service). Direct
 * conversations only; the same pair always lands in the same conversation.
 */
final class Chat
{
    private const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly People $people,
        private readonly ObjectStorage $storage,
        private readonly ChatRelay $relay,
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function contacts(Actor $actor): array
    {
        return $this->people->contacts($actor, withAvatars: true);
    }

    /** @return list<array<string, mixed>> */
    public function conversations(Actor $actor): array
    {
        $ids = $this->memberships($actor)->pluck('conversation_id');
        if ($ids->isEmpty()) {
            return [];
        }
        $rows = ChatConversation::query()->whereKey($ids)->where('is_active', 1)->with(['participants' => static fn ($q) => $q->where('is_active', 1), 'lastMessage'])
            ->orderByDesc('last_message_at')->orderByDesc('updated_at')->get();
        $unread = $this->unreadCounts($actor, $rows->modelKeys());

        return $rows->map(fn (ChatConversation $c): array => $this->conversation($actor, $c, $unread[$c->id] ?? 0))->all();
    }

    /** @return array<string, mixed> */
    public function conversationFor(Actor $actor, int $id): array
    {
        $this->assertMember($actor, $id);
        $c = ChatConversation::query()->with(['participants' => static fn ($q) => $q->where('is_active', 1), 'lastMessage'])->find($id)
            ?? throw ApiException::notFound('Conversation not found.');

        return $this->conversation($actor, $c, $this->unreadCounts($actor, [$c->id])[$c->id] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $target  {type, id}
     * @return array<string, mixed>
     */
    public function direct(Actor $actor, array $target): array
    {
        $type = strtolower(is_scalar($target['type'] ?? null) ? (string) $target['type'] : '');
        $id = is_numeric($target['id'] ?? null) ? (int) $target['id'] : 0;
        if (! in_array($type, Actor::TYPES, true) || $id <= 0) {
            throw ApiException::badRequest('Invalid chat recipient.');
        }
        if ($actor->type === 'admin' && $type === 'admin') {
            throw ApiException::forbidden('Admins can chat with vendors and clients.');
        }
        $this->people->assertCanReach($actor, $type, $id, adminsToAdmins: false);

        $existing = ChatConversation::query()->where('conversation_type', 'direct')->where('is_active', 1)
            ->whereIn('id', $this->memberships($actor)->select('conversation_id'))
            ->whereIn('id', ChatParticipant::query()->where('actor_type', $type)->where('actor_id', $id)->where('is_active', 1)->select('conversation_id'))
            ->value('id');
        if ($existing !== null) {
            return $this->conversationFor($actor, (int) $existing);
        }

        $profiles = $this->people->profiles([[$actor->type, $actor->id], [$type, $id]]);
        $mine = $profiles["{$actor->type}:{$actor->id}"] ?? null;
        $theirs = $profiles["{$type}:{$id}"] ?? throw ApiException::notFound('Chat recipient not found.');
        $id = DB::transaction(function () use ($actor, $type, $id, $mine, $theirs): int {
            $conversation = ChatConversation::query()->create([
                'company_id' => $actor->companyId,
                'vendor_id' => $theirs['vendor_id'] ?? $mine['vendor_id'] ?? $actor->vendorId,
            ]);
            foreach ([[$actor->type, $actor->id, $mine], [$type, $id, $theirs]] as [$t, $i, $p]) {
                ChatParticipant::query()->create([
                    'conversation_id' => $conversation->id, 'actor_type' => $t, 'actor_id' => $i,
                    'display_name' => $p['name'] ?? null, 'avatar' => $this->safeAvatar($p['avatar'] ?? null),
                ]);
            }

            return $conversation->id;
        });

        return $this->conversationFor($actor, $id);
    }

    /**
     * Newest 50 (at most 100), oldest first; before_id pages back.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function messages(Actor $actor, int $conversationId, array $query): array
    {
        $this->assertMember($actor, $conversationId);
        $limit = min(100, (int) ($query['limit'] ?? 50) ?: 50);

        return ChatMessage::query()->where('conversation_id', $conversationId)
            ->when(is_numeric($query['before_id'] ?? null), static fn (Builder $q) => $q->where('id', '<', (int) $query['before_id']))
            ->orderByDesc('id')->limit($limit)->get()->reverse()
            ->map(fn (ChatMessage $m): array => $this->message($m))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $payload  conversation_id, message, attachment {data_url, name, type, size}
     * @return array<string, mixed>
     */
    public function send(Actor $actor, array $payload, bool $broadcast = true): array
    {
        $conversationId = is_numeric($payload['conversation_id'] ?? null) ? (int) $payload['conversation_id'] : 0;
        $text = trim(is_scalar($payload['message'] ?? null) ? (string) $payload['message'] : '');
        if ($conversationId <= 0) {
            throw ApiException::badRequest('conversation_id is required.');
        }
        $attachment = $payload['attachment'] ?? null;
        if ($text === '' && empty($attachment)) {
            throw ApiException::badRequest('Message is required.');
        }
        if (mb_strlen($text) > 5000) {
            throw ApiException::badRequest('Message is too long.');
        }
        $this->assertMember($actor, $conversationId);
        $stored = is_array($attachment) && $attachment !== [] ? $this->attachment($attachment) : null;

        $message = DB::transaction(function () use ($actor, $conversationId, $text, $stored): ChatMessage {
            $message = ChatMessage::query()->create([
                'conversation_id' => $conversationId, 'sender_type' => $actor->type, 'sender_id' => $actor->id,
                'message' => $text !== '' ? $text : ($stored['name'] ?? 'Attachment'),
                'metadata' => $stored === null ? null : ['attachment' => $stored],
            ]);
            ChatConversation::query()->whereKey($conversationId)->update(['last_message_id' => $message->id, 'last_message_at' => now()]);
            $this->markRead($actor, $conversationId, $message->id);

            return $message;
        });
        $shaped = $this->message($message);
        if ($broadcast) {
            $this->relay->emit("chat:{$conversationId}", 'chat:message', $shaped);
        }

        return $shaped;
    }

    /** @return array<string, mixed> the caller's read state */
    public function markRead(Actor $actor, int $conversationId, mixed $messageId = null): array
    {
        $member = $this->assertMember($actor, $conversationId);
        $last = is_numeric($messageId) ? (int) $messageId
            : ChatMessage::query()->where('conversation_id', $conversationId)->max('id');
        $member->forceFill(['last_read_message_id' => $last === null ? null : (int) $last, 'last_read_at' => now()])->save();

        return [
            'conversation_id' => $conversationId, 'actor_type' => $actor->type, 'actor_id' => $actor->id,
            'last_read_message_id' => $member->last_read_message_id, 'last_read_at' => Dates::at($member->last_read_at),
        ];
    }

    public function token(Actor $actor): string
    {
        return $this->relay->token($actor);
    }

    // ---------------------------------------------------------------------

    private function assertMember(Actor $actor, int $conversationId): ChatParticipant
    {
        return $this->memberships($actor)->where('conversation_id', $conversationId)->first()
            ?? throw ApiException::forbidden('You do not have access to this conversation.');
    }

    /** @return Builder<ChatParticipant> */
    private function memberships(Actor $actor): Builder
    {
        return ChatParticipant::query()->where('actor_type', $actor->type)->where('actor_id', $actor->id)->where('is_active', 1);
    }

    /**
     * Messages from others after the caller's last read, per conversation (one query).
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function unreadCounts(Actor $actor, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ChatMessage::query()->withTrashed()->from('chat_messages as m')
            ->join('chat_participants as p', static fn ($j) => $j->on('p.conversation_id', '=', 'm.conversation_id')
                ->where('p.actor_type', $actor->type)->where('p.actor_id', $actor->id))
            ->whereIn('m.conversation_id', $ids)->whereNull('m.deleted_at')
            ->where(static fn ($q) => $q->where('m.sender_type', '!=', $actor->type)->orWhere('m.sender_id', '!=', $actor->id))
            ->where(static fn ($q) => $q->whereNull('p.last_read_message_id')->orWhereColumn('m.id', '>', 'p.last_read_message_id'))
            ->groupBy('m.conversation_id')->selectRaw('m.conversation_id, COUNT(*) as total')->pluck('total', 'conversation_id')
            ->map(static fn ($n): int => (int) $n)->all();
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{url: string, name: string, type: string, size: int}
     */
    private function attachment(array $value): array
    {
        $dataUrl = (string) ($value['data_url'] ?? $value['dataUrl'] ?? '');
        $name = mb_substr((string) ($value['name'] ?? 'attachment'), 0, 255);
        if (preg_match('/^data:[^;]+;base64,/', $dataUrl) !== 1) {
            throw ApiException::badRequest('Attachment must be a valid uploaded file.');
        }
        $estimated = (int) ceil(strlen((string) substr($dataUrl, (int) strpos($dataUrl, ',') + 1)) * 0.75);
        $size = max((int) ($value['size'] ?? 0), $estimated);
        if ($size > self::ATTACHMENT_MAX_BYTES) {
            throw ApiException::badRequest('Attachment must be 10 MB or smaller.');
        }
        $url = $this->storage->storeDataUri($dataUrl, 'chat/attachments', (string) preg_replace('/\.[^.]+$/', '', $name) ?: 'attachment')
            ?? throw ApiException::badRequest('Attachment must be a valid uploaded file.');

        return ['url' => $url, 'name' => $name, 'type' => mb_substr((string) ($value['type'] ?? ''), 0, 100), 'size' => (int) ($value['size'] ?? 0) ?: $estimated];
    }

    private function safeAvatar(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && ! str_starts_with($value, 'data:') && strlen($value) <= 500 ? $value : null;
    }

    /** @return array<string, mixed> */
    private function conversation(Actor $actor, ChatConversation $c, int $unread): array
    {
        $participants = $c->participants->map(fn (ChatParticipant $p): array => $this->participant($p))->values();
        $other = $participants->first(static fn (array $p): bool => ! $actor->is($p['actor_type'], $p['actor_id'])) ?? $participants->first();

        return [
            'id' => $c->id, 'company_id' => $c->company_id, 'vendor_id' => $c->vendor_id, 'conversation_type' => $c->conversation_type,
            'context_type' => $c->getAttribute('context_type'), 'context_id' => null, 'last_message_id' => $c->last_message_id,
            'last_message_at' => Dates::at($c->last_message_at), 'is_active' => $c->getAttribute('is_active'),
            ...$this->times($c), 'deletedAt' => null,
            'participants' => $participants->all(),
            'lastMessage' => $c->lastMessage === null ? null : $this->message($c->lastMessage),
            'title' => $c->title ?: ($other['display_name'] ?? 'Conversation'),
            'other_participant' => $other,
            'unread_count' => $unread,
        ];
    }

    /** @return array<string, mixed> */
    private function participant(ChatParticipant $p): array
    {
        return [
            'id' => $p->id, 'conversation_id' => $p->conversation_id, 'actor_type' => $p->actor_type, 'actor_id' => $p->actor_id,
            'display_name' => $p->display_name, 'avatar' => $p->avatar, 'last_read_message_id' => $p->last_read_message_id,
            'last_read_at' => Dates::at($p->last_read_at), 'is_muted' => 0, 'is_active' => $p->getAttribute('is_active'),
            ...$this->times($p), 'deletedAt' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function message(ChatMessage $m): array
    {
        return [
            'id' => $m->id, 'conversation_id' => $m->conversation_id, 'sender_type' => $m->sender_type, 'sender_id' => $m->sender_id,
            'message' => $m->message, 'message_type' => $m->message_type, 'metadata' => $m->metadata,
            'edited_at' => null, 'deleted_at' => Dates::at($m->getAttribute('deleted_at')), ...$this->times($m),
        ];
    }

    /** @return array{createdAt: string|null, updatedAt: string|null} v1's Sequelize timestamp keys */
    private function times(Model $model): array
    {
        return ['createdAt' => Dates::at($model->getAttribute('created_at')), 'updatedAt' => Dates::at($model->getAttribute('updated_at'))];
    }
}
