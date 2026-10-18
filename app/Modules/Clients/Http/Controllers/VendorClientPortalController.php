<?php

declare(strict_types=1);

namespace App\Modules\Clients\Http\Controllers;

use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Catalog\Http\Resources\V1\PlanTypeResource;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Clients\Actions\ClientSessions;
use App\Modules\Clients\Actions\ClientSignIn;
use App\Modules\Clients\Actions\ClientWriter;
use App\Modules\Clients\Http\Resources\V1\VendorClientResource;
use App\Modules\Clients\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/vendors/client/* — v1's vendor-client portal (cookies
 * client_access_token / client_refresh_token). The clients are the same
 * clients as everywhere else; only the entrance and the wording differ.
 */
final class VendorClientPortalController
{
    private const ACCESS_COOKIE = 'client_access_token';

    private const REFRESH_COOKIE = 'client_refresh_token';

    public function __construct(
        private readonly ClientSignIn $signIn,
        private readonly ClientSessions $sessions,
        private readonly SessionTokens $tokens,
    ) {}

    public function handoff(Request $request): JsonResponse
    {
        $client = $this->signIn->redeemHandoff($request->input('token'));
        $issued = $this->sessions->start($client, $request);
        $response = ApiResponse::success(['client' => $this->present($client)], 'Login successful');

        return $this->tokens->attach($response, self::ACCESS_COOKIE, $issued['access'], self::REFRESH_COOKIE, $issued['refresh']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = $request->input('email');
        if (! is_string($email) || trim($email) === '') {
            throw ApiException::badRequest('Email is required');
        }
        $this->signIn->sendResetCode($email);

        return ApiResponse::success(null, 'If that email is registered, a reset code has been sent. Use it to set a new password within 10 minutes.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $code = $request->input('reset_code');
        $password = $request->input('new_password');
        if (! is_string($email) || $email === '' || ! is_scalar($code) || (string) $code === '' || $password === null || $password === '') {
            throw ApiException::badRequest('Email, reset code and new password are required');
        }
        $this->signIn->resetPassword($email, (string) $code, $password);

        return ApiResponse::success(null, 'Password reset successfully. You can now log in.');
    }

    // ---- signed in ------------------------------------------------------------

    public function logout(Request $request): JsonResponse
    {
        $session = $this->sessions->current($request);
        $session !== null ? $this->sessions->end($session) : $this->client($request)->currentAccessToken()->delete();

        return $this->tokens->forget(ApiResponse::success(null, 'Logged out successfully'), self::ACCESS_COOKIE, self::REFRESH_COOKIE);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(['client' => $this->present($this->client($request))], 'Profile retrieved successfully');
    }

    public function updateProfile(Request $request, ClientWriter $writer): JsonResponse
    {
        $client = $writer->updateVendorPortalProfile($this->client($request), $request->all());

        return ApiResponse::success(['client' => $this->present($client)], 'Profile updated successfully');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $this->signIn->changePassword($client, $request->input('current_password'), $request->input('new_password'), 'vendor');
        $this->sessions->endAll($client, 'password_change');

        return $this->tokens->forget(ApiResponse::success(null, 'Password changed successfully'), self::ACCESS_COOKIE, self::REFRESH_COOKIE);
    }

    /**
     * The tiers this client may be put on: the vendor's own custom tiers and
     * the common ones. (v1 read a `Subscription` model that did not exist —
     * bug 2 — so this always failed.)
     */
    public function plans(Request $request): JsonResponse
    {
        $vendorId = $this->client($request)->vendor_id;
        $query = static fn () => PlanType::query()->withoutGlobalScopes()->where('is_active', 1)->with('menus')->orderBy('sort_order')->orderBy('price');
        $common = $query()->where('is_custom', 0)->get();
        $custom = $query()->where('is_custom', 1)->where('vendor_id', $vendorId)->get();
        $present = static fn (iterable $types): array => collect($types)->map(static fn (PlanType $t): PlanTypeResource => new PlanTypeResource($t))->values()->all();

        return ApiResponse::success([
            'type' => $custom->isNotEmpty() ? 'custom_plus_common' : 'common',
            'vendor_id' => $vendorId,
            'custom_plans' => $present($custom),
            'common_plans' => $present($common),
            'plans' => $present([...$custom, ...$common]),
        ], 'Client subscription plans retrieved');
    }

    private function present(Client $client): VendorClientResource
    {
        return new VendorClientResource($client->loadMissing(['vendor', 'planType']));
    }

    private function client(Request $request): Client
    {
        /** @var Client */
        return $request->user();
    }
}
