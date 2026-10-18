<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * For routes every portal shares (/mail, /chat): the frontend's Next.js proxy
 * says which portal it is in `x-portal-type` (admin | vendor | client), and
 * that portal's own middleware group runs — its cookies, guard and tenant.
 *
 * Without the header the portal is guessed as v1 did, vendor first, then
 * client, then admin: from whichever portal cookie is present, else from
 * which guard accepts the Bearer token. "client" is the vendor-client portal
 * (cookie client_access_token).
 */
final class PortalFromHeader
{
    private const GROUPS = ['vendor' => 'portal.vendor', 'client' => 'portal.vendorclient', 'admin' => 'portal.admin'];

    private const COOKIES = [
        'vendor' => ['vendor_access_token', 'vendor_refresh_token'],
        'client' => ['client_access_token', 'client_refresh_token'],
        'admin' => ['access_token', 'refresh_token'],
    ];

    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        $portal = strtolower((string) $request->header('x-portal-type'));
        if (! isset(self::GROUPS[$portal])) {
            $portal = $this->guess($request);
        }
        if ($portal === null) {
            return ApiResponse::error('Authentication required.', 401);
        }

        $middleware = Arr::flatten(MiddlewareNameResolver::resolve(self::GROUPS[$portal], $this->router->getMiddleware(), $this->router->getMiddlewareGroups()));

        /** @var Response */
        return (new Pipeline(app()))->send($request)->through($middleware)->then($next);
    }

    private function guess(Request $request): ?string
    {
        foreach (self::COOKIES as $portal => $names) {
            foreach ($names as $name) {
                if (is_string($request->cookie($name)) && $request->cookie($name) !== '') {
                    return $portal;
                }
            }
        }
        if ($request->bearerToken() !== null) {
            foreach (['vendor' => 'vendor', 'client' => 'client', 'admin' => 'admin'] as $portal => $guard) {
                if (Auth::guard($guard)->user() !== null) {
                    Auth::forgetGuards();

                    return $portal;
                }
            }
        }

        return null;
    }
}
