<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Catalog\Support\PlanGrants;
use Illuminate\Database\Eloquent\Model;

/**
 * Event menus (v1 /event-menus). Slugs are unique per company (-2, -3... on a
 * clash). Marking a menu Default grants it to every plan at once; switching
 * Default off leaves the grants for the admin to untick per plan (v1).
 *
 * @extends RecordWriter<EventMenu>
 */
final class EventMenuWriter extends RecordWriter
{
    /** The list's quick switches (PATCH /:id/toggle/:field). */
    public const TOGGLES = ['is_active', 'is_default'];

    protected function model(): string
    {
        return EventMenu::class;
    }

    protected function module(): string
    {
        return 'event_menus';
    }

    protected function label(): string
    {
        return 'Menu';
    }

    protected function columns(): array
    {
        return [
            'name', 'slug', 'description', 'remarks', 'menu_group', 'event_category_id', 'is_default',
            'icon', 'color', 'sort_order', 'is_active',
        ];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        /** @var EventMenu|null $existing */
        if ($existing === null || array_key_exists('name', $values)) {
            $values['name'] = trim((string) ($values['name'] ?? ''));
            if ($values['name'] === '') {
                throw ApiException::badRequest('Menu name is required');
            }
        }
        if (array_key_exists('is_default', $values)) {
            $values['is_default'] = Flag::bit($values['is_default']);
        }
        if (array_key_exists('menu_group', $values)) {
            $values['menu_group'] = Flag::oneOf($values['menu_group'], ['core', 'addon'], 'core');
        }
        if (array_key_exists('event_category_id', $values)) {
            $values['event_category_id'] = Flag::id($values['event_category_id']);
        }

        if ($existing === null) {
            $values['slug'] = $this->uniqueSlug((string) (($values['slug'] ?? null) ?: $values['name']), 'slug', null, 'menu');
        } elseif (array_key_exists('slug', $values)) {
            $values['slug'] = $this->uniqueSlug((string) (($values['slug'] ?? null) ?: ($values['name'] ?? $existing->name)), 'slug', $existing->id, 'menu');
        } elseif (isset($values['name']) && $values['name'] !== $existing->name) {
            $values['slug'] = $this->uniqueSlug($values['name'], 'slug', $existing->id, 'menu');
        }

        return $values;
    }

    protected function afterSave(Model $model, array $input, bool $created): void
    {
        /** @var EventMenu $model */
        if ((int) $model->is_default === 1) {
            $this->grantToEveryPlan($model);
        }
        PlanGrants::flush();
    }

    public function toggle(EventMenu $menu, string $field, mixed $value, ?Model $actor): EventMenu
    {
        if (! in_array($field, self::TOGGLES, true)) {
            throw ApiException::badRequest("Unknown toggle \"{$field}\".");
        }

        return $this->update($menu, [$field => Flag::bit($value, $field === 'is_default' ? 0 : 1)], $actor);
    }

    /** Action menu "Duplicate": same settings, "(Copy)", fresh slug. */
    public function duplicate(EventMenu $source, ?Model $actor): EventMenu
    {
        $copy = $source->only($this->columns());
        $copy['name'] = $source->name.' (Copy)';
        unset($copy['slug']);

        return $this->create($copy, $actor);
    }

    /** One statement: grant the menu to each live plan that lacks it, at the end of its list. */
    private function grantToEveryPlan(EventMenu $menu): void
    {
        $now = now();
        $missing = Plan::query()
            ->whereDoesntHave('planMenus', static fn ($q) => $q->where('event_menu_id', $menu->id))
            ->pluck('id');

        PlanMenu::query()->insertOrIgnore($missing->map(static fn (int $planId): array => [
            'plan_id' => $planId, 'event_menu_id' => $menu->id, 'sort_order' => 999, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }
}
