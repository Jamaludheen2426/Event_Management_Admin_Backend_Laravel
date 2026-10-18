<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\Menu;
use Illuminate\Database\Eloquent\Model;

/**
 * Vendor-portal menus (v1 /menus). v1 wrote the raw body with no checks;
 * the name is now required.
 *
 * @extends RecordWriter<Menu>
 */
final class MenuWriter extends RecordWriter
{
    protected function model(): string
    {
        return Menu::class;
    }

    protected function module(): string
    {
        return 'menus';
    }

    protected function label(): string
    {
        return 'Menu';
    }

    protected function columns(): array
    {
        return ['name', 'icon', 'icon_fill_color_light', 'icon_fill_color_dark', 'sort_order', 'is_active', 'display_status'];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if ($existing === null || array_key_exists('name', $values)) {
            $values['name'] = trim((string) ($values['name'] ?? ''));
            if ($values['name'] === '') {
                throw ApiException::badRequest('Menu name is required');
            }
        }
        if (array_key_exists('display_status', $values)) {
            $values['display_status'] = Flag::bit($values['display_status'], 1);
        }

        return $values;
    }
}
