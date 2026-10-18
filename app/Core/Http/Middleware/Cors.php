<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiResponse;
use Closure;
use Fruitcake\Cors\CorsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces Laravel's HandleCors with the two policies the Node API had
 * (config/cors.php explains why):
 *
 *  - public site routes: any origin, no credentials;
 *  - everything else:    only FRONTEND_URL origins, with credentials.
 *
 * A request with no Origin header (server to server, the Flutter app) is not a
 * browser cross-origin call and passes untouched.
 */
final class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Origin')) {
            return $next($request);
        }

        $public = $this->isPublicSiteRoute($request);
        $origin = (string) $request->headers->get('Origin');

        if (! $public && ! in_array($origin, (array) config('cors.frontend_origins', []), true)) {
            return ApiResponse::error('CORS blocked: '.$origin, 403);
        }

        $cors = new CorsService([
            'allowedOrigins' => $public ? ['*'] : [$origin],
            'allowedMethods' => ['GET', 'HEAD', 'PUT', 'PATCH', 'POST', 'DELETE'],
            'allowedHeaders' => ['*'],
            'exposedHeaders' => [],
            'maxAge' => 0,
            'supportsCredentials' => ! $public,
        ]);

        if ($cors->isPreflightRequest($request)) {
            $response = $cors->handlePreflightRequest($request);

            return $cors->varyHeader($response, 'Access-Control-Request-Method');
        }

        $response = $next($request);

        return $cors->addActualRequestHeaders($response, $request);
    }

    private function isPublicSiteRoute(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        return str_starts_with($path, (string) config('cors.public_prefix'))
            && ! in_array($path, (array) config('cors.cookie_bearing_public_paths', []), true);
    }
}
