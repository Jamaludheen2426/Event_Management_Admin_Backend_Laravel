<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Auth\SessionTokens;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Node API renewed an expired access token transparently from the refresh
 * cookie, inside its auth middleware. The frontends rely on that, so this does
 * the same.
 *
 * Cheap by design: the access cookie expires together with its token (15 min),
 * so a missing access cookie is the signal. Only then is the refresh token
 * looked up. Bearer callers (the Flutter app) refresh explicitly instead.
 *
 * Usage: RefreshFromCookie::class.':access_token,refresh_token'
 */
final class RefreshFromCookie
{
    public function __construct(private readonly SessionTokens $tokens) {}

    public function handle(Request $request, Closure $next, string $accessCookie, string $refreshCookie): Response
    {
        $fresh = null;

        if ($request->bearerToken() === null
            && ! $request->cookies->has($accessCookie)
            && $request->cookies->has($refreshCookie)) {
            $refresh = $this->tokens->refreshToken((string) $request->cookie($refreshCookie));
            $owner = $refresh?->tokenable;

            if ($refresh !== null && $owner instanceof HasApiTokens) {
                $fresh = $this->tokens->issueAccess($owner, $refresh->session_id === null ? null : (int) $refresh->session_id);
                $request->headers->set('Authorization', 'Bearer '.$fresh);
            }
        }

        $response = $next($request);

        if ($fresh === null || ! $response instanceof Response) {
            return $response;
        }

        return $this->tokens->attach($response, $accessCookie, $fresh);
    }
}
