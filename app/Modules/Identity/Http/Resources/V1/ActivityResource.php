<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources\V1;

use App\Core\Activity\Activity;
use App\Core\Http\Resources\V1Resource;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * v1 activity row: { id, action, module, description, ip_address, user_id,
 * created_at, user: { id, full_name, email } }. old/new values travel in
 * `properties` (already redacted when written).
 *
 * @mixin Activity
 *
 * @property Activity $resource
 */
final class ActivityResource extends V1Resource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;
        $causer = $activity->relationLoaded('causer') ? $activity->getRelation('causer') : null;
        $user = $causer instanceof User ? $causer : null;

        return [
            'id' => $activity->id,
            'action' => $activity->getAttribute('event'),
            'module' => $activity->getAttribute('log_name'),
            'description' => $activity->getAttribute('description'),
            'ip_address' => $activity->ip_address,
            'user_agent' => $activity->getAttribute('user_agent'),
            'user_id' => $user?->id,
            'properties' => $activity->getAttribute('properties'),
            'created_at' => $this->isoDate($activity->getAttribute('created_at')),
            'user' => $user === null ? null : ['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email],
        ];
    }
}
