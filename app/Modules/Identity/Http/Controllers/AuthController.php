<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Activity\ActivityLogger;
use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Identity\Actions\Auth\AdminPasswordReset;
use App\Modules\Identity\Actions\Auth\ChangeAdminPassword;
use App\Modules\Identity\Actions\Auth\LoginAdmin;
use App\Modules\Identity\Actions\Auth\UpdateAdminProfile;
use App\Modules\Identity\Http\Middleware\EnsureAdminAccountUsable;
use App\Modules\Identity\Http\Requests\Auth\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\Auth\ResetPasswordRequest;
use App\Modules\Identity\Http\Requests\Auth\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\V1\AuthUserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * /api/v1/auth/* — admin panel sign-in. Cookie names, lifetimes, messages and
 * the { user } payload are v1's.
 */
final class AuthController
{
    private const ACCESS_COOKIE = 'access_token';

    private const REFRESH_COOKIE = 'refresh_token';

    public function __construct(private readonly SessionTokens $tokens) {}

    public function login(Request $request, LoginAdmin $login): JsonResponse
    {
        $user = $login->handle($this->text($request, 'email'), $this->text($request, 'password'));
        $issued = $this->tokens->issue($user);

        $response = ApiResponse::success(['user' => $this->authUser($user)], 'Login successful');

        return $this->tokens->attach($response, self::ACCESS_COOKIE, $issued['access'], self::REFRESH_COOKIE, $issued['refresh']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $plain = $request->cookie(self::REFRESH_COOKIE) ?? $request->input('refresh_token');
        if (! is_string($plain) || $plain === '') {
            throw ApiException::unauthorized('Refresh token required');
        }

        $user = $this->tokens->ownerOfRefreshToken($plain);
        if (! $user instanceof User) {
            throw ApiException::unauthorized('Invalid or expired refresh token');
        }
        if (! $user->isUsable()) {
            throw ApiException::unauthorized('User inactive or not found');
        }

        $access = $this->tokens->issueAccess($user);
        $response = ApiResponse::success(['accessToken' => $access, 'token' => $access], 'Token refreshed successfully');

        return $this->tokens->attach($response, self::ACCESS_COOKIE, $access);
    }

    public function logout(Request $request, ActivityLogger $activity): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->currentAccessToken()->delete();
        $refresh = $request->cookie(self::REFRESH_COOKIE);
        $this->tokens->revoke(is_string($refresh) ? $refresh : null);

        $activity->log('auth', 'logout', 'User logged out', $user, causer: $user);

        return $this->tokens->forget(ApiResponse::success(null, 'Logout successful'), self::ACCESS_COOKIE, self::REFRESH_COOKIE);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(['user' => $this->authUser($user)]);
    }

    public function changePassword(ChangePasswordRequest $request, ChangeAdminPassword $change): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $change->handle($user, (string) $request->validated('current_password'), (string) $request->validated('new_password'));

        return ApiResponse::success(null, 'Password changed successfully');
    }

    public function updateProfile(UpdateProfileRequest $request, UpdateAdminProfile $update): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{full_name?: string, phone?: string|null, avatar?: string|null, timezone?: string|null} $changes */
        $changes = $request->validated();
        $update->handle($user, $changes);

        return ApiResponse::success(['user' => $this->authUser($user)], 'Profile updated successfully');
    }

    public function forgotPassword(Request $request, AdminPasswordReset $reset): JsonResponse
    {
        $email = $this->text($request, 'email');
        if ($email !== null && $email !== '') {
            $reset->send($email);
        }

        return ApiResponse::success(null, AdminPasswordReset::SENT);
    }

    public function verifyResetOtp(ResetPasswordRequest $request, AdminPasswordReset $reset): JsonResponse
    {
        $reset->verify((string) $request->validated('email'), (string) $request->validated('otp'));

        return ApiResponse::success(null, 'OTP verified successfully');
    }

    public function resetPassword(ResetPasswordRequest $request, AdminPasswordReset $reset): JsonResponse
    {
        $reset->reset((string) $request->validated('email'), (string) $request->validated('otp'), (string) $request->validated('password'));

        return ApiResponse::success(null, 'Password reset successfully');
    }

    private function authUser(User $user): AuthUserResource
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->company_id);
        $user->unsetRelation('roles')->load(AuthUserResource::RELATIONS);
        EnsureAdminAccountUsable::assertUsable($user);

        return new AuthUserResource($user);
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : null;
    }
}
