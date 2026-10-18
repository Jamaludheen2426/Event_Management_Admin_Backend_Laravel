<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Actions;

use App\Core\Activity\ActivityLogger;
use App\Core\Auth\ResetCodes;
use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Modules\Identity\Mail\PasswordChangedMail;
use App\Modules\Identity\Mail\PasswordResetCodeMail;
use App\Modules\Vendors\Enums\VendorStatus;
use App\Modules\Vendors\Http\Middleware\EnsureStaffUsable;
use App\Modules\Vendors\Models\VendorUser;
use App\Modules\Vendors\Support\VendorPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Signing in to the vendor portal (owners) and the staff portal (staff),
 * resetting and changing their passwords, and the one-minute handoff tokens
 * that move a signed-in owner from one vendor app to another.
 *
 * Messages are v1's. Unlike v1, every session is a revocable token: logout,
 * a password change and deactivation end it at once.
 */
final class VendorSignIn
{
    private const HANDOFF_SECONDS = 60;

    public function __construct(
        private readonly ResetCodes $codes,
        private readonly SessionTokens $tokens,
        private readonly ActivityLogger $activity,
    ) {}

    public function owner(?string $email, ?string $password): VendorUser
    {
        if ($email === null || $email === '' || $password === null || $password === '') {
            throw ApiException::badRequest('Email and password are required');
        }
        $owner = VendorUser::findByEmail($email, owner: true) ?? throw ApiException::unauthorized('Invalid email or password');
        if ($owner->vendor === null || $owner->vendor->status !== VendorStatus::Active) {
            throw ApiException::forbidden('Your account is inactive. Please contact the administrator.');
        }
        $this->assertPassword($owner, $password);
        $this->signedIn($owner, 'vendor_auth');

        return $owner;
    }

    public function staff(?string $email, ?string $password): VendorUser
    {
        if ($email === null || $email === '' || $password === null || $password === '') {
            throw ApiException::badRequest('Email and password are required');
        }
        $staff = VendorUser::findByEmail($email, owner: false) ?? throw ApiException::unauthorized('Invalid email or password');
        $this->assertPassword($staff, $password);
        EnsureStaffUsable::assertUsable($staff, atLogin: true);
        $this->signedIn($staff, 'staff_auth');

        return $staff;
    }

    /** Forgot password: same answer whether or not the email is known. */
    public function sendResetCode(string $email, bool $owner): void
    {
        $user = VendorUser::findByEmail($email, owner: $owner);
        if ($user === null) {
            return;
        }
        if ($owner && $user->vendor?->status !== VendorStatus::Active) {
            throw ApiException::forbidden('Your account is inactive. Please contact the administrator.');
        }
        if (! $owner && ! $user->is_active) {
            throw ApiException::forbidden('Your account is inactive. Please contact your vendor.');
        }

        $code = $this->codes->issue($this->scope($owner), $user->email);
        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail($user->name, $code, ResetCodes::MINUTES));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function verifyResetCode(string $email, string $code, bool $owner): void
    {
        VendorUser::findByEmail($email, owner: $owner) ?? throw ApiException::badRequest('Invalid email or OTP');
        $this->codes->check($this->scope($owner), $email, $code);
    }

    public function resetPassword(string $email, string $code, mixed $password, bool $owner): void
    {
        $user = VendorUser::findByEmail($email, owner: $owner) ?? throw ApiException::badRequest('Invalid email or OTP');
        $this->codes->check($this->scope($owner), $email, $code);
        VendorPassword::assertValid($password);

        $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
        $this->codes->forget($this->scope($owner), $email);
        $this->tokens->revokeAll($user);

        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user->name));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function changePassword(VendorUser $user, mixed $current, mixed $new): void
    {
        if (! is_string($current) || trim($current) === '') {
            throw ApiException::badRequest('Current password is required');
        }
        VendorPassword::assertValid($new);
        if ($user->password === null || ! Hash::check($current, $user->password)) {
            throw ApiException::badRequest('Current password is incorrect');
        }

        $user->forceFill(['password' => $new, 'password_changed_at' => now()])->save();
        $this->tokens->revokeAll($user);
        $this->activity->log($user->is_owner ? 'vendor_security' : 'staff_security', 'change_password', 'Password changed successfully', $user, causer: $user);
    }

    /** A single-use token, valid one minute, that signs the owner in elsewhere. */
    public function createHandoff(VendorUser $owner): string
    {
        $token = Str::random(64);
        Cache::put('vendor-handoff:'.hash('sha256', $token), $owner->id, self::HANDOFF_SECONDS);

        return $token;
    }

    public function redeemHandoff(?string $token): VendorUser
    {
        if ($token === null || $token === '') {
            throw ApiException::badRequest('Handoff token is required');
        }
        $id = Cache::pull('vendor-handoff:'.hash('sha256', $token));
        $owner = is_numeric($id) ? VendorUser::query()->withoutGlobalScopes()->whereNull('deleted_at')->find((int) $id) : null;
        if ($owner === null || ! $owner->is_owner) {
            throw ApiException::unauthorized('Invalid or expired handoff token.');
        }
        if ($owner->vendor?->status !== VendorStatus::Active) {
            throw ApiException::forbidden('Your account is inactive. Please contact the administrator.');
        }

        return $owner;
    }

    private function assertPassword(VendorUser $user, string $password): void
    {
        if ($user->password === null || ! Hash::check($password, $user->password)) {
            throw ApiException::unauthorized('Invalid email or password');
        }
        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
    }

    private function signedIn(VendorUser $user, string $module): void
    {
        $user->last_login_at = now();
        $user->save();
        $this->activity->log($module, 'login', 'Logged in successfully', $user, causer: $user, companyId: $user->tenantCompanyId());
    }

    private function scope(bool $owner): string
    {
        return $owner ? 'vendor' : 'staff';
    }
}
