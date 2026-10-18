<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Auth;

use App\Core\Activity\ActivityLogger;
use App\Core\Auth\SessionTokens;
use App\Core\Exceptions\ApiException;
use App\Modules\Identity\Mail\PasswordChangedMail;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The signed-in admin changes their own password. Every session of the user —
 * this one included — ends, as in v1 (the frontend sends them to login).
 */
final class ChangeAdminPassword
{
    public function __construct(
        private readonly SessionTokens $tokens,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(User $user, string $current, string $new): void
    {
        if ($user->password === null || ! Hash::check($current, $user->password)) {
            throw ApiException::badRequest('Current password is incorrect');
        }

        $user->forceFill([
            'password' => $new,
            'password_changed_at' => now(),
            'updated_by' => $user->id,
        ])->save();

        $this->tokens->revokeAll($user);
        $this->activity->log('auth', 'password_change', 'User changed password', $user, causer: $user);

        try {
            Mail::to($user->email)->send(new PasswordChangedMail($user->full_name));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
