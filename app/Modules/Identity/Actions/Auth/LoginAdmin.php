<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Auth;

use App\Core\Activity\ActivityLogger;
use App\Core\Exceptions\ApiException;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Http\Middleware\EnsureAdminAccountUsable;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Email + password sign-in for the admin panel.
 *
 * One answer for "no such email" and "wrong password" (v1 did the same).
 * Passwords hashed by the Node API (bcryptjs, "$2a$") verify as they are and
 * are re-hashed with Laravel's bcrypt on the first successful sign-in.
 */
final class LoginAdmin
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(?string $email, ?string $password): User
    {
        if ($email === null || $email === '' || $password === null || $password === '') {
            throw ApiException::unauthorized('Invalid email or password');
        }

        $user = Tenancy::bypass(static fn (): ?User => User::query()->where('email', $email)->first());

        if ($user === null || $user->password === null || ! Hash::check($password, $user->password)) {
            throw ApiException::unauthorized('Invalid email or password');
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->company_id);
        EnsureAdminAccountUsable::assertUsable($user);

        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
        $user->last_login_at = now();
        $user->save();

        $this->activity->log('auth', 'login', 'User logged in', $user, causer: $user, companyId: $user->company_id);

        return $user;
    }
}
