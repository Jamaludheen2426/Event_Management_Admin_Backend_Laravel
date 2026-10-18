<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Resources\V1;

use App\Core\Http\Resources\V1Resource;
use App\Modules\Messaging\Models\PushConfig;
use Illuminate\Http\Request;

/**
 * v1 PushNotificationConfig.toJSON: the row without the service account and
 * key, plus whether they are stored and a masked key.
 *
 * @property PushConfig $resource
 */
final class PushConfigResource extends V1Resource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        $raw = $this->resource->getAttributes();
        $hasKey = ($raw['private_key'] ?? null) !== null;

        return [
            ...$this->resource->attributesToArray(),
            'has_service_account' => $hasKey || ($raw['service_account_json'] ?? null) !== null,
            'has_private_key' => $hasKey,
            ...($hasKey ? ['private_key_masked' => str_repeat('•', 32)] : []),
        ];
    }
}
