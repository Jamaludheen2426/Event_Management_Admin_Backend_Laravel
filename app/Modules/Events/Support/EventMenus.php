<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Events\Models\Event;

/**
 * Whether one feature's menu (by slug) shows on an event: a Default menu
 * always does, another one when the host switched it on. Returned in the
 * shape the feature screens read (menu_id, is_default, show_in_app).
 */
final class EventMenus
{
    /** @return array{menu_id: int|null, is_default: bool, show_in_app: bool} */
    public static function state(Event $event, string $slug): array
    {
        $event->loadMissing(['client', 'menuChoices']);
        $menu = EventMenu::query()->withoutGlobalScopes()->where('company_id', $event->client->tenantCompanyId())->where('slug', $slug)->first(['id', 'is_default']);
        $default = $menu !== null && (int) $menu->is_default === 1;
        $selected = $menu !== null && $event->menuChoices->where('selected', true)->contains('event_menu_id', $menu->id);

        return ['menu_id' => $menu?->id, 'is_default' => $default, 'show_in_app' => $default || $selected];
    }
}
