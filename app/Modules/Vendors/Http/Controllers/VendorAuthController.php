<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Http\Controllers;

use App\Core\Activity\Activity;
use App\Core\Auth\SessionTokens;
use App\Core\Contracts\ObjectStorage;
use App\Core\Exceptions\ApiException;
use App\Core\Http\ApiResponse;
use App\Modules\Platform\Models\Plugin;
use App\Modules\Vendors\Actions\VendorSignIn;
use App\Modules\Vendors\Actions\VendorWriter;
use App\Modules\Vendors\Http\Resources\V1\VendorResource;
use App\Modules\Vendors\Models\Vendor;
use App\Modules\Vendors\Models\VendorUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * /api/v1/vendors/auth/* — the vendor owner's sign-in and own profile.
 * Cookies vendor_access_token / vendor_refresh_token, as in v1.
 */
final class VendorAuthController
{
    public const ACCESS_COOKIE = 'vendor_access_token';

    public const REFRESH_COOKIE = 'vendor_refresh_token';

    public function __construct(
        private readonly VendorSignIn $signIn,
        private readonly SessionTokens $tokens,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $owner = $this->signIn->owner($this->text($request, 'email'), $this->text($request, 'password'));

        return $this->withSession(ApiResponse::success(['vendor' => $this->present($owner->vendor)], 'Login successful'), $owner);
    }

    /** v1 allowed logout without a valid session; it always clears the cookies. */
    public function logout(Request $request): JsonResponse
    {
        foreach ([self::ACCESS_COOKIE, self::REFRESH_COOKIE] as $cookie) {
            $plain = $request->cookie($cookie);
            $this->tokens->revoke(is_string($plain) ? $plain : null);
        }

        return $this->tokens->forget(ApiResponse::success(null, 'Logged out successfully'), self::ACCESS_COOKIE, self::REFRESH_COOKIE);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = $this->text($request, 'email') ?: throw ApiException::badRequest('Email is required');
        $this->signIn->sendResetCode($email, owner: true);

        return ApiResponse::success(null, 'If that email is registered, an OTP has been sent');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $email = $this->text($request, 'email');
        $otp = $this->text($request, 'otp');
        $password = $request->input('new_password');
        if ($email === null || $email === '' || $otp === null || $otp === '' || $password === null || $password === '') {
            throw ApiException::badRequest('email, otp, and new_password are required');
        }
        $this->signIn->resetPassword($email, $otp, $password, owner: true);

        return ApiResponse::success(null, 'Password reset successfully');
    }

    public function handoff(Request $request): JsonResponse
    {
        $owner = $this->signIn->redeemHandoff($this->text($request, 'token'));

        return $this->withSession(ApiResponse::success(['vendor' => $this->present($owner->vendor)], 'Handoff successful'), $owner);
    }

    // ---- signed in --------------------------------------------------------

    public function me(Request $request): JsonResponse
    {
        $vendor = $this->owner($request)->vendor;
        $plugin = Plugin::query()->where('slug', 'website-management')->where('is_active', true)->exists();

        return ApiResponse::success($this->present($vendor, detail: true)->withFeatures($vendor->website_enabled && $plugin), 'Vendor profile retrieved');
    }

    public function updateProfile(Request $request, VendorWriter $writer): JsonResponse
    {
        $vendor = $writer->updateOwnProfile($this->owner($request), $request->all());

        return ApiResponse::success($this->present($vendor), 'Profile updated successfully');
    }

    public function about(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($this->owner($request)->vendor, detail: true), 'About company retrieved');
    }

    public function updateAbout(Request $request, VendorWriter $writer): JsonResponse
    {
        $vendor = $writer->updateOwnProfile($this->owner($request), $request->all());

        return ApiResponse::success($this->present($vendor, detail: true), 'About company updated successfully');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $current = $request->input('current_password');
        $new = $request->input('new_password');
        if ($current === null || $current === '' || $new === null || $new === '') {
            throw ApiException::badRequest('current_password and new_password are required');
        }
        $this->signIn->changePassword($this->owner($request), $current, $new);

        return ApiResponse::success(null, 'Password changed successfully');
    }

    public function handoffToken(Request $request): JsonResponse
    {
        return ApiResponse::success(['handoff_token' => $this->signIn->createHandoff($this->owner($request))], 'Handoff token created');
    }

    /** The owner's own activity (sign-ins, profile changes), newest first. */
    public function activity(Request $request): JsonResponse
    {
        $owner = $this->owner($request);
        $page = Activity::query()
            ->where('causer_type', $owner->getMorphClass())
            ->whereIn('causer_id', VendorUser::query()->select('id'))
            ->when(is_string($request->query('module')) && $request->query('module') !== '', static fn ($q) => $q->where('log_name', $request->query('module')))
            ->when(is_string($request->query('action')) && $request->query('action') !== '', static fn ($q) => $q->where('event', $request->query('action')))
            ->when(is_string($request->query('search')) && $request->query('search') !== '', static fn ($q) => $q->where('description', 'like', '%'.$request->query('search').'%'))
            ->latest('id')
            ->paginate(min(1000, max(1, (int) $request->query('limit', '10') ?: 10)), ['*'], 'page', max(1, (int) $request->query('page', '1')))
            ->through(static fn (Activity $a): array => [
                'id' => $a->id,
                'action' => $a->getAttribute('event'),
                'module' => $a->getAttribute('log_name'),
                'description' => $a->getAttribute('description'),
                'ip_address' => $a->ip_address,
                'user_agent' => $a->getAttribute('user_agent'),
                'created_at' => $a->getAttribute('created_at')?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ]);

        return ApiResponse::nestedPage($page, 'Activity logs retrieved');
    }

    /** POST /vendors/auth/upload (multipart `file`) — the vendor portal's image picker. */
    public function upload(Request $request, ObjectStorage $storage): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ApiException::badRequest('No file provided');
        }
        $request->validate(['file' => ['file', 'max:'.config('media.max_kilobytes'), 'mimetypes:'.implode(',', (array) config('media.allowed_mimes'))]], [
            'file.max' => 'File size exceeds the 10MB limit.',
            'file.mimetypes' => 'This file type is not allowed',
        ]);

        return ApiResponse::success(['file' => $storage->upload($file, (string) $request->input('folder', 'vendors'))], 'File uploaded successfully');
    }

    // ---------------------------------------------------------------------

    private function withSession(JsonResponse $response, VendorUser $owner): JsonResponse
    {
        $issued = $this->tokens->issue($owner);

        return $this->tokens->attach($response, self::ACCESS_COOKIE, $issued['access'], self::REFRESH_COOKIE, $issued['refresh']);
    }

    private function present(Vendor $vendor, bool $detail = false): VendorResource
    {
        $vendor->loadMissing($detail ? ['owner', 'planType', 'district', 'city'] : ['owner', 'planType']);

        return new VendorResource($vendor);
    }

    private function owner(Request $request): VendorUser
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
