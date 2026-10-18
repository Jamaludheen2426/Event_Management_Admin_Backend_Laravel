<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Messaging\Actions\Chat;
use App\Modules\Messaging\Support\Actor;
use App\Modules\Messaging\Support\ChatRelay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/chat/* for the three portals (v1 chat.controller), and the
 * relay's own /api/internal/chat/* (chat-socket/ calls these with the
 * shared secret, for an actor it verified from a socket token).
 */
final class ChatController
{
    public function __construct(
        private readonly Chat $chat,
        private readonly ChatRelay $relay,
    ) {}

    public function token(Request $request): JsonResponse
    {
        return ApiResponse::success(['token' => $this->chat->token($this->actor($request))], 'Socket token issued');
    }

    public function contacts(Request $request): JsonResponse
    {
        return ApiResponse::success($this->chat->contacts($this->actor($request)), 'Chat contacts fetched successfully');
    }

    public function conversations(Request $request): JsonResponse
    {
        $rows = $this->chat->conversations($this->actor($request));

        return ApiResponse::success(['rows' => $rows, 'count' => count($rows)], 'Conversations fetched successfully');
    }

    public function direct(Request $request): JsonResponse
    {
        return ApiResponse::success($this->chat->direct($this->actor($request), $request->all()), 'Conversation ready');
    }

    public function messages(Request $request, int $id): JsonResponse
    {
        $rows = $this->chat->messages($this->actor($request), $id, $request->query());

        return ApiResponse::success(['rows' => $rows, 'count' => count($rows)], 'Messages fetched successfully');
    }

    public function send(Request $request): JsonResponse
    {
        return ApiResponse::created($this->chat->send($this->actor($request), $request->all()), 'Message sent successfully');
    }

    public function read(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->chat->markRead($this->actor($request), $id, $request->input('message_id')), 'Conversation marked as read');
    }

    // ---- relay ------------------------------------------------------------

    public function relayJoin(Request $request): JsonResponse
    {
        return ApiResponse::success($this->chat->conversationFor($this->relayActor($request), (int) $request->input('conversation_id')));
    }

    /** The relay broadcasts the result itself. */
    public function relaySend(Request $request): JsonResponse
    {
        return ApiResponse::success($this->chat->send($this->relayActor($request), (array) $request->input('payload', []), broadcast: false));
    }

    public function relayRead(Request $request): JsonResponse
    {
        return ApiResponse::success($this->chat->markRead($this->relayActor($request), (int) $request->input('conversation_id'), $request->input('message_id')));
    }

    private function actor(Request $request): Actor
    {
        return Actor::of($request->user());
    }

    /** The actor inside a socket token the relay received. */
    private function relayActor(Request $request): Actor
    {
        if (! $this->relay->secretMatches($request->header('X-Relay-Secret'))) {
            throw ApiException::forbidden('Forbidden');
        }
        $claims = $this->relay->verify((string) $request->input('token'));
        if ($claims === null || ! is_array($claims['actor'] ?? null)) {
            throw ApiException::unauthorized('Authentication required.');
        }

        return Actor::fromArray($claims['actor']);
    }
}
