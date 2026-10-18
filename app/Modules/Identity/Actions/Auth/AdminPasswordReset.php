<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Auth;

use App\Core\Activity\ActivityLogger;
use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Mail\PasswordChangedMail;
use App\Modules\Identity\Mail\PasswordResetCodeMail;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Forgot password for admins: a 6-digit code by email, valid 10 minutes.
 *
 * Differences from v1, on purpose:
 *  - the code is stored hashed (password_reset_tokens), not in plain text on the user;
 *  - "no such email" and "code sent" give the same answer, so the form cannot
 *    be used to find out which emails have accounts;
 *  - the routes are rate limited (throttle:code);
 *  - a successful reset ends all of the user's sessions.
 */
final class AdminPasswordReset
{
    public const SENT = 'OTP sent to your email';

    public function __construct(
        private readonly SessionTokens $tokens,
        private readonly ActivityLogger $activity,
    ) {}

    public function send(string $email): void
    {
        $user = $this->find($email);
        if ($user === null) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($code), 'created_at' => now()],
        );

        $this->activity->log('auth', 'password_reset_request', 'Password reset OTP requested', $user, causer: $user, companyId: $user->company_id);

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail($user->full_name, $code, $this->minutes()));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function verify(string $email, string $code): User
    {
        $user = $this->find($email) ?? throw ApiException::badRequest('Invalid email or OTP');

        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        if ($row === null || ! Hash::check($code, (string) $row->token)) {
            throw ApiException::badRequest('Invalid OTP');
        }

        if (now()->subMinutes($this->minutes())->greaterThan($row->created_at)) {
            throw ApiException::badRequest('OTP has expired');
        }

        return $user;
    }

    public function reset(string $email, string $code, string $password): void
    {
        $user = $this->verify($email, $code);

        $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        $this->tokens->revokeAll($user);

        $this->activity->log('auth', 'password_reset', 'Password reset completed', $user, causer: $user, companyId: $user->company_id);

        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user->full_name));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function find(string $email): ?User
    {
        return Tenancy::bypass(static fn (): ?User => User::query()->where('email', $email)->first());
    }

    private function minutes(): int
    {
        return (int) config('auth.reset_code_minutes', 10);
    }
}
