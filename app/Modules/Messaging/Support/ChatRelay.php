<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The bridge to chat-socket/ (the Node socket.io relay). Laravel owns every
 * chat row; the relay only holds the open sockets.
 *
 *  - Laravel signs a 10-minute socket token (HS256, CHAT_SOCKET_SECRET) that
 *    the relay verifies on connect (v1 GET /chat/token).
 *  - A message sent over REST is pushed to the room through the relay's
 *    /internal/emit, with the same secret.
 *  - The relay calls Laravel's /internal/chat/* with the secret to join,
 *    send and mark read.
 */
final class ChatRelay
{
    public function token(Actor $actor): string
    {
        $now = time();

        return $this->sign(['actor' => $actor->toArray(), 'iat' => $now, 'exp' => $now + 600]);
    }

    /** @return array<string, mixed>|null the claims of a valid, unexpired token */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! hash_equals($this->signature($parts[0].'.'.$parts[1]), $parts[2])) {
            return null;
        }
        $claims = json_decode($this->decode($parts[1]), true);

        return is_array($claims) && (int) ($claims['exp'] ?? 0) > time() ? $claims : null;
    }

    public function secretMatches(?string $given): bool
    {
        $secret = $this->secret(false);

        return $secret !== '' && is_string($given) && hash_equals($secret, $given);
    }

    /** Pushes an event to a room. A relay that is down never fails the request. */
    public function emit(string $room, string $event, mixed $data): void
    {
        $url = rtrim((string) config('services.chat_socket.url'), '/');
        if ($url === '' || $this->secret(false) === '') {
            return;
        }
        try {
            Http::timeout(3)->withHeaders(['X-Relay-Secret' => $this->secret()])
                ->post("{$url}/internal/emit", ['room' => $room, 'event' => $event, 'data' => $data]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims): string
    {
        $head = $this->encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $this->encode((string) json_encode($claims));

        return "{$head}.{$body}.".$this->signature("{$head}.{$body}");
    }

    private function signature(string $data): string
    {
        return $this->encode(hash_hmac('sha256', $data, $this->secret(), true));
    }

    private function secret(bool $required = true): string
    {
        $secret = (string) config('services.chat_socket.secret');
        if ($secret === '' && $required) {
            throw new RuntimeException('CHAT_SOCKET_SECRET is not set.');
        }

        return $secret;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
