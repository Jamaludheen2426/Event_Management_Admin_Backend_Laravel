<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Controllers;

use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Vendors\Actions\VendorSignIn;
use App\Modules\Vendors\Http\Resources\V1\StaffResource;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * /api/v1/vendors/staff/auth/* — staff sign-in for the staff portal.
 * Cookies staff_access_token / staff_refresh_token, as in v1.
 */
final class StaffAuthController
{
    private const ACCESS_COOKIE = 'staff_access_token';

    private const REFRESH_COOKIE = 'staff_refresh_token';

    public function __construct(
        private readonly VendorSignIn $signIn,
        private readonly SessionTokens $tokens,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $staff = $this->signIn->staff($this->text($request, 'email'), $this->text($request, 'password'));
        $issued = $this->tokens->issue($staff);
        $response = ApiResponse::success(['staff' => $this->present($staff)], 'Login successful');

        return $this->tokens->attach($response, self::ACCESS_COOKIE, $issued['access'], self::REFRESH_COOKIE, $issued['refresh']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = $this->text($request, 'email') ?: throw ApiException::badRequest('Email is required');
        $this->signIn->sendResetCode($email, owner: false);

        return ApiResponse::success(null, 'If that email is registered, an OTP has been sent');
    }

    public function verifyResetOtp(Request $request): JsonResponse
    {
        $email = $this->text($request, 'email');
        $otp = $this->text($request, 'otp');
        if ($email === null || $email === '' || $otp === null || $otp === '') {
            throw ApiException::badRequest('Email and OTP are required');
        }
        $this->signIn->verifyResetCode($email, $otp, owner: false);

        return ApiResponse::success(null, 'OTP verified successfully');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $email = $this->text($request, 'email');
        $otp = $this->text($request, 'otp');
        $password = $request->input('password');
        if ($email === null || $email === '' || $otp === null || $otp === '' || $password === null || $password === '') {
            throw ApiException::badRequest('Email, OTP, and new password are required');
        }
        $this->signIn->resetPassword($email, $otp, $password, owner: false);

        return ApiResponse::success(null, 'Password reset successfully');
    }

    // ---- signed in --------------------------------------------------------

    public function logout(Request $request): JsonResponse
    {
        $this->staff($request)->currentAccessToken()->delete();
        $refresh = $request->cookie(self::REFRESH_COOKIE);
        $this->tokens->revoke(is_string($refresh) ? $refresh : null);

        return $this->tokens->forget(ApiResponse::success(null, 'Logged out successfully'), self::ACCESS_COOKIE, self::REFRESH_COOKIE);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(['staff' => $this->present($this->staff($request))], 'Profile retrieved successfully');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $current = $request->input('current_password');
        $new = $request->input('new_password');
        if ($current === null || $current === '' || $new === null || $new === '') {
            throw ApiException::badRequest('Current and new password are required');
        }
        $this->signIn->changePassword($this->staff($request), $current, $new);

        return ApiResponse::success(null, 'Password changed successfully');
    }

    private function present(VendorUser $staff): StaffResource
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($staff->vendor_id);

        return new StaffResource($staff->unsetRelation('roles')->load(['roles.permissions.group', 'vendor', 'department']));
    }

    private function staff(Request $request): VendorUser
    {
        /** @var VendorUser */
        return $request->user();
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : null;
    }
}
