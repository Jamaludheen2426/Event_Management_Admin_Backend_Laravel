<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use Illuminate\Http\Request;

/** v1 EmailConfig.toJSON: every column but the secrets, plus has_api_key. */
final class EmailConfigResource extends RowResource
{
    protected function extra(Request $request): array
    {
        return ['has_api_key' => ($this->resource->getAttributes()['api_key'] ?? null) !== null];
    }
}
