<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets every frontend keep the httpOnly cookie it already uses.
 *
 * Each portal reads its own cookie names (access_token, vendor_access_token,
 * staff_access_token, website_client_access_token, ...). The first one present
 * is handed to Sanctum as a Bearer token. An Authorization header the caller
 * sent itself always wins, which is how the Flutter app authenticates.
 *
 * Usage: CookieToBearer::class.':website_client_access_token,client_access_token'
 */
final class CookieToBearer
{
    public function handle(Request $request, Closure $next, string ...$cookies): Response
    {
        if ($request->bearerToken() === null) {
            foreach ($cookies as $name) {
                $token = $request->cookie($name);
                if (is_string($token) && $token !== '') {
                    $request->headers->set('Authorization', 'Bearer '.$token);
                    break;
                }
            }
        }

        return $next($request);
    }
}
