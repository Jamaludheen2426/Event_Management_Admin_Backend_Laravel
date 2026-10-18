<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Auth\SessionTokens;
use App\Core\Http\ApiResponse;
use App\Modules\Clients\Actions\ClientSessions;
use App\Modules\Clients\Actions\ClientTwoFactorAuth;
use App\Modules\Clients\Models\Client;
use App\Modules\Clients\Models\ClientSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/client/security/* — where the client is signed in, and two-factor
 * authentication (v1 clientSecurity.controller).
 */
final class ClientSecurityController
{
    public function __construct(
        private readonly ClientSessions $sessions,
        private readonly ClientTwoFactorAuth $twoFactor,
    ) {}

    public function sessions(Request $request): JsonResponse
    {
        $list = $this->sessions->list($this->client($request), $this->current($request));

        return ApiResponse::success([
            'sessions' => $list,
            'current_session_id' => collect($list)->firstWhere('is_current', true)['id'] ?? null,
            'location_available' => false,
            'location_note' => 'Sign-in locations are not available — no IP-location service is connected, so the IP address is shown instead.',
        ], 'Sessions retrieved');
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $this->sessions->endById($this->client($request), $id);

        return ApiResponse::success(['sessions' => $this->list($request)], 'Signed out on that device');
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $count = $this->sessions->endOthers($this->client($request), $this->current($request));

        return ApiResponse::success(
            ['sessions' => $this->list($request), 'revoked' => $count],
            $count === 1 ? 'Signed out of 1 other session' : "Signed out of {$count} other sessions",
        );
    }

    public function devices(Request $request): JsonResponse
    {
        return ApiResponse::success(['devices' => $this->list($request), 'location_available' => false], 'Devices retrieved');
    }

    public function removeDevice(Request $request, int $id): JsonResponse
    {
        $this->sessions->endById($this->client($request), $id);

        return ApiResponse::success(['devices' => $this->list($request)], 'Device removed');
    }

    // ---- two-factor -------------------------------------------------------------

    public function twoFactor(Request $request): JsonResponse
    {
        return ApiResponse::success($this->twoFactor->status($this->client($request)), 'Two-factor status retrieved');
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        return ApiResponse::success($this->twoFactor->beginSetup($this->client($request)), 'Scan the QR code with your authenticator app');
    }

    public function confirmTwoFactor(Request $request, SessionTokens $tokens): JsonResponse
    {
        $client = $this->client($request);
        $result = $this->twoFactor->confirmSetup($client, $request->input('code'));
        $response = ApiResponse::success([
            ...$result,
            'note' => 'Save these now. They are stored hashed and cannot be shown again — you can only generate a new set.',
        ], 'Two-factor authentication is on');

        if ($request->boolean('trust_device')) {
            $session = $this->current($request);
            if ($session !== null) {
                $this->sessions->trust($session);
            }
            $value = $this->twoFactor->trustCookieValue($client);
            if ($value !== null) {
                $response->headers->setCookie($tokens->cookie(ClientTwoFactorAuth::TRUST_COOKIE, $value, ClientSessions::TRUST_DAYS * 24 * 60));
            }
        }

        return $response;
    }

    public function disableTwoFactor(Request $request, SessionTokens $tokens): JsonResponse
    {
        $result = $this->twoFactor->disable($this->client($request), $request->input('password'), $request->input('code'));

        return $tokens->forget(ApiResponse::success($result, 'Two-factor authentication is off'), ClientTwoFactorAuth::TRUST_COOKIE);
    }

    public function backupCodes(Request $request): JsonResponse
    {
        return ApiResponse::success([
            ...$this->twoFactor->regenerateBackupCodes($this->client($request)),
            'note' => 'Your previous backup codes no longer work.',
        ], 'New backup codes generated');
    }

    /** @return list<array<string, mixed>> */
    private function list(Request $request): array
    {
        return $this->sessions->list($this->client($request), $this->current($request));
    }

    private function current(Request $request): ?ClientSession
    {
        $session = $request->attributes->get('client_session');

        return $session instanceof ClientSession ? $session : null;
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
