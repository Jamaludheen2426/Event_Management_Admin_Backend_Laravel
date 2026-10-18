<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Clients\Actions\ClientSessions;
use App\Modules\Clients\Actions\ClientSignIn;
use App\Modules\Clients\Actions\ClientTwoFactorAuth;
use App\Modules\Clients\Actions\SocialSignIn;
use App\Modules\Clients\Http\Resources\V1\ClientResource;
use App\Modules\Clients\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * /api/v1/public/website-clients/* — client sign-in for the client portal, the
 * public site and the mobile app (v1 websiteClient.controller).
 *
 * The web gets httpOnly cookies (website_client_access_token / _refresh_token);
 * the app reads the tokens from the body of the OTP and refresh answers.
 */
final class ClientAuthController
{
    public const ACCESS_COOKIE = 'website_client_access_token';

    public const REFRESH_COOKIE = 'website_client_refresh_token';

    public function __construct(
        private readonly ClientSignIn $signIn,
        private readonly ClientSessions $sessions,
        private readonly ClientTwoFactorAuth $twoFactor,
        private readonly SessionTokens $tokens,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $client = $this->signIn->register($request->all(), $this->vendor($request));

        return ApiResponse::success(['client' => $this->present($client)], 'Account created successfully', 201);
    }

    public function login(Request $request): JsonResponse
    {
        $client = $this->signIn->passwordLogin($request->all(), $this->vendor($request));

        if ($this->twoFactor->isEnabled($client)) {
            $trust = $request->cookie(ClientTwoFactorAuth::TRUST_COOKIE);
            if (! $this->twoFactor->isTrustedBrowser($client, is_string($trust) ? $trust : null)) {
                return ApiResponse::success([
                    'requires_2fa' => true,
                    'challenge_token' => $this->signIn->issueChallenge($client),
                    'expires_in' => 600,
                ], 'Enter your two-factor code to finish signing in');
            }
        }

        return $this->signedIn($client, $request, 'Login successful');
    }

    public function verifyLogin2fa(Request $request): JsonResponse
    {
        $client = $this->signIn->challengeClient($request->input('challenge_token'));
        if (! $this->twoFactor->verifyForLogin($client, $request->input('code'))) {
            throw ApiException::unauthorized('That code is not right. Check your authenticator app and try again.');
        }
        $this->signIn->forgetChallenge($request->input('challenge_token'));

        $response = $this->signedIn($client, $request, 'Login successful');
        if ($request->boolean('trust_device')) {
            $value = $this->twoFactor->trustCookieValue($client);
            if ($value !== null) {
                $response->headers->setCookie($this->tokens->cookie(ClientTwoFactorAuth::TRUST_COOKIE, $value, ClientSessions::TRUST_DAYS * 24 * 60));
            }
        }

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $this->sessions->endByRefreshToken($this->refreshToken($request));

        return $this->tokens->forget(ApiResponse::success(null, 'Logged out successfully'), self::ACCESS_COOKIE, self::REFRESH_COOKIE);
    }

    public function requestLoginOtp(Request $request): JsonResponse
    {
        return ApiResponse::success($this->signIn->requestLoginOtp($request->all(), $this->vendor($request)), 'Verification code sent.');
    }

    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $client = $this->signIn->verifyLoginOtp($request->all(), $this->vendor($request));

        return $this->signedIn($client, $request, 'Login successful', withTokens: true);
    }

    public function refresh(Request $request): JsonResponse
    {
        $token = $this->refreshToken($request) ?? throw ApiException::unauthorized('A refresh token is required.');
        $issued = $this->sessions->rotate($token);
        $this->signIn->assertCanSignIn($issued['client']);

        return $this->respondWithSession($issued['client'], $issued['access'], $issued['refresh'], 'Session refreshed', withTokens: true);
    }

    // ---- social sign-in -------------------------------------------------------

    public function oauthProviders(SocialSignIn $social): JsonResponse
    {
        return ApiResponse::success(['providers' => $social->configuredProviders()]);
    }

    public function oauthStart(Request $request, SocialSignIn $social, string $provider): RedirectResponse
    {
        $returnTo = $request->query('return_to') ?? $request->headers->get('referer');

        return redirect()->away($social->authorizeUrl($provider, $returnTo, $this->vendor($request)));
    }

    public function oauthCallback(Request $request, SocialSignIn $social, string $provider): RedirectResponse|Response
    {
        $state = $social->pullState($request->query('state'));
        if ($state === null || $state['p'] !== mb_strtolower($provider)) {
            return response('Sign-in link has expired or is invalid. Please try again.', 400);
        }
        if (! $social->isAllowedReturnTo($state['r'])) {
            return response('Sign-in return address is not an allowed site.', 400);
        }

        $back = static function (array $params) use ($state): RedirectResponse {
            $separator = str_contains($state['r'], '?') ? '&' : '?';

            return redirect()->away($state['r'].$separator.http_build_query($params));
        };
        if ($request->query('error') !== null || ! is_string($request->query('code'))) {
            return $back(['auth' => 'cancelled']);
        }

        try {
            ['client' => $client, 'created' => $created] = $social->completeSignIn($state['p'], (int) $state['v']);
        } catch (ApiException $e) {
            return $back(['auth' => 'error', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return $back(['auth' => 'error', 'message' => 'Sign-in failed. Please try again.']);
        }

        $issued = $this->sessions->start($client, $request);
        $response = $back([
            'auth' => 'success',
            'mode' => $created ? 'signup' : 'login',
            'provider' => $state['p'],
            'name' => $client->name,
            ...($client->mobile === null ? ['needs_mobile' => '1', 'link_token' => $this->signIn->issueMobileLinkToken($client)] : []),
        ]);

        return $this->tokens->attach($response, self::ACCESS_COOKIE, $issued['access'], self::REFRESH_COOKIE, $issued['refresh']);
    }

    public function sendMobileOtp(Request $request): JsonResponse
    {
        $result = $this->signIn->sendMobileLinkCode($request->input('token'), $request->input('dial_code'), $request->input('mobile'));

        return ApiResponse::success($result, 'Verification code sent.');
    }

    public function verifyMobileOtp(Request $request): JsonResponse
    {
        $client = $this->signIn->verifyMobileLinkCode($request->input('token'), $request->input('dial_code'), $request->input('mobile'), $request->input('otp'));

        return ApiResponse::success(['client' => $this->present($client)], 'Mobile number verified.');
    }

    // ---- helpers ------------------------------------------------------------

    private function signedIn(Client $client, Request $request, string $message, bool $withTokens = false): JsonResponse
    {
        $issued = $this->sessions->start($client, $request);

        return $this->respondWithSession($client, $issued['access'], $issued['refresh'], $message, $withTokens);
    }

    private function respondWithSession(Client $client, string $access, string $refresh, string $message, bool $withTokens): JsonResponse
    {
        $data = ['client' => $this->present($client)];
        if ($withTokens) {
            $data += [
                'access_token' => $access,
                'refresh_token' => $refresh,
                'token_type' => 'Bearer',
                'expires_in' => SessionTokens::ACCESS_MINUTES * 60,
            ];
        }

        return $this->tokens->attach(ApiResponse::success($data, $message), self::ACCESS_COOKIE, $access, self::REFRESH_COOKIE, $refresh);
    }

    private function present(Client $client): ClientResource
    {
        return new ClientResource($client->loadMissing(['vendor', 'favourites']));
    }

    private function vendor(Request $request): int
    {
        return $this->signIn->vendorId($request->input('vendor_id') ?? $request->query('vendor_id'));
    }

    private function refreshToken(Request $request): ?string
    {
        $token = $request->input('refresh_token') ?? $request->cookie(self::REFRESH_COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
