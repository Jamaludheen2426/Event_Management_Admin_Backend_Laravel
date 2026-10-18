<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions\Auth;

use App\Core\Activity\ActivityLogger;
use App\Core\Contracts\ObjectStorage;
use App\Modules\Identity\Models\User;

/**
 * The admin edits their own name, phone, avatar and timezone. An avatar sent
 * as a data URI is uploaded first; the old one is removed from storage.
 */
final class UpdateAdminProfile
{
    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array{full_name?: string, phone?: string|null, avatar?: string|null, timezone?: string|null} $changes */
    public function handle(User $user, array $changes): User
    {
        $old = $user->only(['full_name', 'phone', 'avatar', 'timezone']);

        if (array_key_exists('avatar', $changes)) {
            $changes['avatar'] = $this->storage->storeDataUri($changes['avatar'], 'avatars', 'avatar');
            if ($changes['avatar'] !== $old['avatar']) {
                $this->storage->deleteByUrl($old['avatar']);
            }
        }

        $user->fill($changes + ['updated_by' => $user->id])->save();

        $this->activity->log('profile', 'update', 'User updated profile', $user, $old, $changes, $user);

        return $user;
    }
}
