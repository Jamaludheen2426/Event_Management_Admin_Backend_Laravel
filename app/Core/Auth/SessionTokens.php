<?php

declare(strict_types=1);

namespace App\Core\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login sessions for every portal: a 15-minute access token and a 7-day
 * refresh token, both rows in personal_access_tokens, so logout, password
 * changes and "deactivate user" can revoke them. (The Node API's vendor and
 * staff tokens were stateless and could not be revoked.)
 *
 * The cookies carry the same names and lifetimes as v1, so the frontends and
 * their Next.js proxies do not change.
 */
final class SessionTokens
{
    public const ACCESS_MINUTES = 15;

    public const REFRESH_DAYS = 7;

    /**
     * @param  int|null  $sessionId  the sign-in both tokens belong to (clients)
     * @return array{access: string, refresh: string} plain-text tokens
     */
    public function issue(HasApiTokens&Model $owner, ?int $sessionId = null): array
    {
        return [
            'access' => $this->issueAccess($owner, $sessionId),
            'refresh' => $this->create($owner, 'refresh', now()->addDays(self::REFRESH_DAYS), $sessionId),
        ];
    }

    public function issueAccess(HasApiTokens&Model $owner, ?int $sessionId = null): string
    {
        return $this->create($owner, 'access', now()->addMinutes(self::ACCESS_MINUTES), $sessionId);
    }

    /** A live refresh token, or null. */
    public function refreshToken(?string $plain): ?PersonalAccessToken
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        $token = PersonalAccessToken::findToken($plain);
        if (! $token instanceof PersonalAccessToken || ! $token->can('refresh') || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }

    /**
     * The owner of a live refresh token, or null.
     */
    public function ownerOfRefreshToken(?string $plain): ?Model
    {
        $owner = $this->refreshToken($plain)?->tokenable;

        return $owner instanceof Model ? $owner : null;
    }

    /** End one sign-in: both of its tokens. */
    public function revokeSession(int $sessionId): void
    {
        PersonalAccessToken::query()->where('session_id', $sessionId)->delete();
    }

    public function revoke(?string $plain): void
    {
        if ($plain !== null && $plain !== '') {
            PersonalAccessToken::findToken($plain)?->delete();
        }
    }

    public function revokeAll(HasApiTokens&Model $owner): void
    {
        $owner->tokens()->delete();
    }

    /**
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    public function attach(Response $response, string $accessCookie, ?string $access, ?string $refreshCookie = null, ?string $refresh = null): Response
    {
        if ($access !== null) {
            $response->headers->setCookie($this->cookie($accessCookie, $access, self::ACCESS_MINUTES));
        }
        if ($refreshCookie !== null && $refresh !== null) {
            $response->headers->setCookie($this->cookie($refreshCookie, $refresh, self::REFRESH_DAYS * 24 * 60));
        }

        return $response;
    }

    /**
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    public function forget(Response $response, string ...$names): Response
    {
        foreach ($names as $name) {
            $response->headers->setCookie($this->cookie($name, '', -2628000));
        }

        return $response;
    }

    /** Sanctum's createToken(), plus the session id, in one insert. */
    private function create(HasApiTokens&Model $owner, string $ability, \DateTimeInterface $expiresAt, ?int $sessionId): string
    {
        $entropy = Str::random(40);
        $plain = config('sanctum.token_prefix', '').$entropy.hash('crc32b', $entropy);
        /** @var PersonalAccessToken $token */
        $token = $owner->tokens()->create([
            'name' => $ability,
            'token' => hash('sha256', $plain),
            'abilities' => [$ability],
            'expires_at' => $expiresAt,
            'session_id' => $sessionId,
        ]);

        return $token->getKey().'|'.$plain;
    }

    /**
     * httpOnly always; cross-site (SameSite=None; Secure) in production, where
     * the frontends live on other origins; Lax locally, where everything is
     * localhost and None would require HTTPS.
     */
    public function cookie(string $name, string $value, int $minutes): Cookie
    {
        $production = app()->isProduction();

        return Cookie::create(
            name: $name,
            value: $value,
            expire: $minutes > 0 ? now()->addMinutes($minutes) : now()->subYear(),
            path: '/',
            secure: $production,
            httpOnly: true,
            raw: false,
            sameSite: $production ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
        );
    }
}
