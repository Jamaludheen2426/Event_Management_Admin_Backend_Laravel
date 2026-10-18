<?php

declare(strict_types=1);

namespace App\Modules\Clients\Actions;

use App\Core\Auth\PersonalAccessToken;
use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\Tenancy;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientSession;
use App\Modules\Clients\Support\DeviceInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A client's sign-ins, one row per device (v1 clientSession.service).
 *
 * Each session owns one access and one refresh token (session_id), so ending a
 * session — logout, "sign out that device", a password change — revokes
 * exactly its tokens at once. A refresh rotates the pair inside the same
 * session; the refresh token is single-use.
 */
final class ClientSessions
{
    public const TRUST_DAYS = 30;

    private const TOUCH_MINUTES = 5;

    public function __construct(private readonly SessionTokens $tokens) {}

    /** @return array{session: ClientSession, access: string, refresh: string} */
    public function start(Client $client, Request $request, bool $trust = false): array
    {
        $session = ClientSession::query()->create([
            'client_id' => $client->id,
            ...DeviceInfo::of($request),
            'last_active_at' => now(),
            'expires_at' => now()->addDays(SessionTokens::REFRESH_DAYS),
            'trusted_until' => $trust ? now()->addDays(self::TRUST_DAYS) : null,
        ]);

        return ['session' => $session, ...$this->tokens->issue($client, $session->id)];
    }

    /**
     * Exchange a refresh token for a new pair in the same session.
     *
     * @return array{client: Client, session: ClientSession, access: string, refresh: string}
     */
    public function rotate(?string $refreshToken): array
    {
        $token = $this->tokens->refreshToken($refreshToken);
        $client = $token?->tokenable;
        if ($token === null || ! $client instanceof Client) {
            throw ApiException::unauthorized('Your session has expired. Please sign in again.');
        }

        $session = $token->session_id !== null ? ClientSession::query()->find($token->session_id) : null;
        if ($session === null || ! $session->isLive()) {
            throw ApiException::unauthorized('This session was signed out. Please sign in again.');
        }

        return DB::transaction(function () use ($token, $client, $session): array {
            // Single use: whoever deletes the row first wins a concurrent race.
            if (PersonalAccessToken::query()->whereKey($token->getKey())->delete() !== 1) {
                throw ApiException::unauthorized('This session was signed out. Please sign in again.');
            }
            PersonalAccessToken::query()->where('session_id', $session->id)->delete();
            $session->forceFill(['last_active_at' => now(), 'expires_at' => now()->addDays(SessionTokens::REFRESH_DAYS)])->save();

            return ['client' => $client, 'session' => $session, ...$this->tokens->issue($client, $session->id)];
        });
    }

    /** The session behind the request's access token, if any. */
    public function current(Request $request): ?ClientSession
    {
        $user = $request->user();
        $token = $user instanceof Client ? $user->currentAccessToken() : null;
        $id = $token instanceof PersonalAccessToken ? $token->session_id : null;

        return $id !== null ? ClientSession::query()->where('client_id', $user->id)->find($id) : null;
    }

    /** "Last active" only needs to be roughly right: written at most every 5 minutes. */
    public function touch(ClientSession $session): void
    {
        if ($session->last_active_at === null || $session->last_active_at->lt(now()->subMinutes(self::TOUCH_MINUTES))) {
            $session->forceFill(['last_active_at' => now()])->save();
        }
    }

    public function end(ClientSession $session, string $reason = 'logout'): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
        }
        $this->tokens->revokeSession($session->id);
    }

    /** Logout by refresh token (the cookie or the app's body), with or without a live access token. */
    public function endByRefreshToken(?string $refreshToken, string $reason = 'logout'): void
    {
        $token = $this->tokens->refreshToken($refreshToken);
        if ($token === null) {
            return;
        }
        $session = $token->session_id !== null ? Tenancy::bypass(static fn () => ClientSession::query()->find($token->session_id)) : null;
        $session !== null ? $this->end($session, $reason) : $token->delete();
    }

    public function endById(Client $client, int $id, string $reason = 'revoked'): ClientSession
    {
        $session = ClientSession::query()->where('client_id', $client->id)->find($id)
            ?? throw ApiException::notFound('That session was not found.');
        $this->end($session, $reason);

        return $session;
    }

    /** @return int how many other sessions were ended */
    public function endOthers(Client $client, ?ClientSession $current, string $reason = 'revoked_all'): int
    {
        $others = ClientSession::query()
            ->where('client_id', $client->id)
            ->whereNull('revoked_at')
            ->when($current !== null, static fn ($q) => $q->whereKeyNot($current?->id))
            ->pluck('id');
        if ($others->isEmpty()) {
            return 0;
        }

        ClientSession::query()->whereIn('id', $others)->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        PersonalAccessToken::query()->whereIn('session_id', $others)->delete();

        return $others->count();
    }

    /** Every session and token of the client (account closed, admin deactivation). */
    public function endAll(Client $client, string $reason): void
    {
        $this->endOthers($client, null, $reason);
        $this->tokens->revokeAll($client);
    }

    public function trust(ClientSession $session): void
    {
        $session->forceFill(['trusted_until' => now()->addDays(self::TRUST_DAYS)])->save();
    }

    /** @return list<array<string, mixed>> live sessions, most recently active first */
    public function list(Client $client, ?ClientSession $current): array
    {
        return ClientSession::query()
            ->where('client_id', $client->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_active_at')
            ->limit(50)
            ->get()
            ->map(fn (ClientSession $s): array => $this->present($s, $current))
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(ClientSession $s, ?ClientSession $current): array
    {
        $iso = static fn ($date): ?string => $date?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $s->id,
            'is_current' => $current !== null && $current->id === $s->id,
            'transport' => $s->transport,
            'device_name' => $s->device_name ?? ($s->transport === 'app' ? 'Mobile app' : 'Unknown device'),
            'device_type' => $s->device_type,
            'browser' => $s->browser,
            'os' => $s->os,
            'ip_address' => $s->ip_address,
            'location' => $s->location,
            'last_active_at' => $iso($s->last_active_at),
            'created_at' => $iso($s->created_at),
            'expires_at' => $iso($s->expires_at),
            'is_trusted' => $s->trusted_until !== null && $s->trusted_until->isFuture(),
            'trusted_until' => $iso($s->trusted_until),
        ];
    }
}
