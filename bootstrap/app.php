<?php

declare(strict_types=1);

use App\Core\Exceptions\ApiExceptionRenderer;
use App\Core\Http\Middleware\CookieToBearer;
use App\Core\Http\Middleware\Cors;
use App\Core\Http\Middleware\RefreshFromCookie;
use App\Core\Http\Middleware\ResolveTenant;
use App\Core\Http\Middleware\SnakeCaseInput;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;

/*
| API routes are not listed here: every module mounts its own route files under
| /api/v1 through App\Core\Support\ModuleServiceProvider.
*/
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Render's (or any) load balancer: the visitor's IP (rate limits,
        // sessions, activity log) and https come from the X-Forwarded-* headers.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        // An API has no login page: a signed-out request always gets the JSON 401
        // (v1), also when it did not ask for JSON (a browser, curl).
        $middleware->redirectGuestsTo(static fn (): ?string => null);

        // Two CORS policies (public site vs. cookie-carrying portals) — see config/cors.php.
        $middleware->replace(HandleCors::class, Cors::class);

        // camelCase body keys become snake_case, exactly as the Node API did.
        $middleware->api(prepend: [SnakeCaseInput::class]);

        // One group per portal, each keeping its v1 cookie names.
        $middleware->group('portal.guest', []);
        $middleware->group('portal.admin', [
            RefreshFromCookie::class.':access_token,refresh_token',
            CookieToBearer::class.':access_token',
            'auth:admin',
            ResolveTenant::class,
            'account.admin',
        ]);
        $middleware->group('portal.vendor', [
            RefreshFromCookie::class.':vendor_access_token,vendor_refresh_token',
            CookieToBearer::class.':vendor_access_token',
            'auth:vendor',
            ResolveTenant::class,
            'account.vendor',
        ]);
        $middleware->group('portal.staff', [
            RefreshFromCookie::class.':staff_access_token,staff_refresh_token',
            CookieToBearer::class.':staff_access_token',
            'auth:vendor',
            ResolveTenant::class,
            'account.staff',
        ]);
        $middleware->group('portal.client', [
            RefreshFromCookie::class.':website_client_access_token,website_client_refresh_token',
            CookieToBearer::class.':website_client_access_token,client_access_token',
            'auth:client',
            ResolveTenant::class,
            'account.client',
        ]);
        // v1's separate vendor-client portal (/vendors/client/*): the same
        // clients, its own cookie and its own wording.
        $middleware->group('portal.vendorclient', [
            RefreshFromCookie::class.':client_access_token,client_refresh_token',
            CookieToBearer::class.':client_access_token',
            'auth:client',
            ResolveTenant::class,
            'account.client:vendor',
        ]);

        // Laravel sorts middleware by priority. These lines pin the order:
        //   RefreshFromCookie → CookieToBearer → auth → ResolveTenant → SubstituteBindings
        // Without them it would authenticate before the cookie became a bearer
        // token, and bind route models before the tenant was known.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, CookieToBearer::class);
        $middleware->prependToPriorityList(CookieToBearer::class, RefreshFromCookie::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
