<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Middleware;

use App\Modules\Clients\Actions\ClientSessions;
use App\Modules\Clients\Actions\ClientSignIn;
use App\Modules\Clients\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 'account.client' (client portal and app) / 'account.client:vendor' (v1
 * vendor-client portal): the signed-in client must still be allowed in —
 * active, login access on, vendor not suspended — on every request, so
 * switching a client off takes effect at once. Also stamps the session's
 * "last active" (at most every 5 minutes).
 */
final class EnsureClientUsable
{
    public function __construct(
        private readonly ClientSignIn $signIn,
        private readonly ClientSessions $sessions,
    ) {}

    public function handle(Request $request, Closure $next, ?string $portal = null): Response
    {
        $client = $request->user();
        if ($client instanceof Client) {
            $this->signIn->assertCanSignIn($client, vendorPortal: $portal === 'vendor', atLogin: false);

            $session = $this->sessions->current($request);
            if ($session !== null) {
                $this->sessions->touch($session);
                $request->attributes->set('client_session', $session);
            }
        }

        return $next($request);
    }
}
