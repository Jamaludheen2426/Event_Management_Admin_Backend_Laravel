<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\Menu;
use App\Modules\Catalog\Models\PlanType;
use Illuminate\Database\Eloquent\Model;

/**
 * Vendor subscription tiers (v1 /plan-types). The menus a tier unlocks were a
 * JSON column (menu_ids); they are the plan_type_menu pivot now and still
 * arrive and leave as menu_ids.
 *
 * @extends RecordWriter<PlanType>
 */
final class PlanTypeWriter extends RecordWriter
{
    protected function model(): string
    {
        return PlanType::class;
    }

    protected function module(): string
    {
        return 'plan_types';
    }

    protected function label(): string
    {
        return 'Plan type';
    }

    protected function columns(): array
    {
        return [
            'name', 'description', 'price', 'discounted_price', 'validity', 'features', 'sort_order', 'is_active',
            'is_custom', 'vendor_id', 'label_color',
        ];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if ($existing === null || array_key_exists('name', $values)) {
            $values['name'] = trim((string) ($values['name'] ?? ''));
            if ($values['name'] === '') {
                throw ApiException::badRequest('Plan type name is required');
            }
        }
        foreach (['price', 'discounted_price'] as $money) {
            if (array_key_exists($money, $values)) {
                $values[$money] = is_numeric($values[$money]) ? round((float) $values[$money], 2) : ($money === 'price' ? 0 : null);
            }
        }
        if (array_key_exists('validity', $values)) {
            $values['validity'] = Flag::id($values['validity']);
        }
        if (array_key_exists('is_custom', $values)) {
            $values['is_custom'] = Flag::bit($values['is_custom']);
        }
        if (array_key_exists('vendor_id', $values)) {
            $values['vendor_id'] = Flag::id($values['vendor_id']);
        }

        return $values;
    }

    protected function afterSave(Model $model, array $input, bool $created): void
    {
        /** @var PlanType $model */
        if (! array_key_exists('menu_ids', $input) || ! is_array($input['menu_ids'])) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map(Flag::id(...), $input['menu_ids']))));
        $known = Menu::query()->whereKey($ids)->pluck('id')->all();
        $sync = [];
        foreach ($ids as $position => $id) {
            if (in_array($id, $known, true)) {
                $sync[$id] = ['sort_order' => $position];
            }
        }
        $model->menus()->sync($sync);
    }
}
