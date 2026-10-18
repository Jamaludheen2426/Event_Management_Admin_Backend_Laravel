<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\V1;

use App\Core\Http\Resources\RowResource;
use Illuminate\Http\Request;

/** Template row + notificationCategory {id, name, icon, color} + category {id, name}. */
final class NotificationTemplateResource extends RowResource
{
    protected function extra(Request $request): array
    {
        return [
            ...$this->brief('notificationCategory', ['id', 'name', 'icon', 'color']),
            ...$this->brief('category', ['id', 'name']),
        ];
    }
}
