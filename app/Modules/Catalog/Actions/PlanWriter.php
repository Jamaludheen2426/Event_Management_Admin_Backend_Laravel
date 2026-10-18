<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Core\Crud\RecordWriter;
use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanBadge;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Catalog\Support\PlanGrants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Subscription plans (v1 /subscription-plans): price, limits and the event
 * menus a plan grants.
 *
 * Menu grants: the Default (locked) menus are always kept, whatever the
 * screen sends — v1 learned this when a screen that left a locked menu out of
 * its submission silently removed the Free plan's Guests section.
 *
 * @extends RecordWriter<Plan>
 */
final class PlanWriter extends RecordWriter
{
    public const REASONS = [
        'deactivation' => [
            'No longer offered', 'Replaced by another plan', 'Pricing under review',
            'Low subscriber count', 'Temporarily unavailable', 'Other',
        ],
        'deletion' => [
            'Plan is not required', 'Created by mistake', 'Duplicate plan', 'Replaced by another plan', 'Other',
        ],
    ];

    private const STORAGE_MAX = 100;

    protected function model(): string
    {
        return Plan::class;
    }

    protected function module(): string
    {
        return 'subscription_plans';
    }

    protected function label(): string
    {
        return 'Subscription plan';
    }

    protected function columns(): array
    {
        return [
            'name', 'plan_code', 'plan_type_id', 'billing_cycle', 'short_description', 'event_category_id',
            'currency_code', 'price', 'trial_days', 'is_visible', 'is_active', 'sort_order', 'plan_badge_id',
            'max_events', 'max_guests_per_event', 'max_rsvp_per_event', 'max_photos', 'max_videos',
            'storage_limit', 'storage_unit',
        ];
    }

    protected function prepare(array $values, ?Model $existing): array
    {
        if ($existing === null || array_key_exists('name', $values)) {
            $values['name'] = trim((string) ($values['name'] ?? ''));
            if ($values['name'] === '') {
                throw ApiException::badRequest('Plan name is required');
            }
        }
        if ($existing === null || array_key_exists('plan_code', $values)) {
            $values['plan_code'] = self::code($values['plan_code'] ?? '');
            if ($values['plan_code'] === '') {
                throw ApiException::badRequest('Plan code is required');
            }
        }
        foreach (['plan_type_id', 'event_category_id'] as $id) {
            if (array_key_exists($id, $values)) {
                $values[$id] = Flag::id($values[$id]);
            }
        }
        if (array_key_exists('billing_cycle', $values)) {
            $values['billing_cycle'] = Flag::oneOf($values['billing_cycle'], ['monthly', 'quarterly', 'yearly', 'lifetime'], 'monthly');
        }
        if (array_key_exists('price', $values)) {
            $values['price'] = is_numeric($values['price']) ? round((float) $values['price'], 2) : 0;
        }
        if (array_key_exists('trial_days', $values)) {
            $values['trial_days'] = Flag::clamp($values['trial_days'], 0, 3650, 0);
        }
        if (array_key_exists('is_visible', $values)) {
            $values['is_visible'] = Flag::bit($values['is_visible'], 1);
        }

        return $this->badge($this->limits($values));
    }

    protected function assertUnique(array $values, ?int $exceptId): void
    {
        if ($this->taken($this->query(), ['plan_code' => $values['plan_code']], $exceptId)) {
            throw ApiException::badRequest("Plan code \"{$values['plan_code']}\" is already in use.");
        }
    }

    protected function afterSave(Model $model, array $input, bool $created): void
    {
        /** @var Plan $model */
        if (array_key_exists('menus', $input) && is_array($input['menus'])) {
            $this->syncMenus($model, $input['menus']);
        } elseif ($created) {
            $this->syncMenus($model, []);
        }
        PlanGrants::flush();
    }

    /** @return array{deactivation: list<string>, deletion: list<string>} */
    public function reasons(): array
    {
        return self::REASONS;
    }

    /** @param array<string, mixed> $input */
    public function deactivate(Plan $plan, array $input, ?Model $actor): Plan
    {
        [$reason, $comments] = $this->reason($input, 'deactivation');
        $plan->forceFill([
            'is_active' => 0,
            'deactivation_reason' => $reason,
            'deactivation_comments' => $comments,
            'deactivated_at' => now(),
            'deactivated_by' => $actor?->getKey(),
            'updated_by' => $actor?->getKey(),
        ])->save();
        $this->activity->log($this->module(), 'deactivate', "Deactivated plan: {$plan->name}", $plan, [], compact('reason', 'comments'));

        return $plan;
    }

    public function reactivate(Plan $plan, ?Model $actor): Plan
    {
        $plan->forceFill([
            'is_active' => 1,
            'deactivation_reason' => null,
            'deactivation_comments' => null,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'updated_by' => $actor?->getKey(),
        ])->save();
        $this->activity->log($this->module(), 'reactivate', "Reactivated plan: {$plan->name}", $plan);

        return $plan;
    }

    /** @param array<string, mixed> $input */
    public function deleteWithReason(Plan $plan, array $input, ?Model $actor): Plan
    {
        [$reason, $comments] = $this->reason($input, 'deletion');
        $plan->forceFill(['deletion_reason' => $reason, 'deletion_comments' => $comments, 'deleted_by' => $actor?->getKey()])->save();
        $this->delete($plan, $actor);
        PlanGrants::flush();

        return $plan;
    }

    /** "(Copy)", plan code CODE-COPY / CODE-COPY-2..., same menus. */
    public function duplicate(Plan $source, ?Model $actor): Plan
    {
        $copy = $source->only($this->columns());
        $copy['name'] = $source->name.' (Copy)';
        $code = $source->plan_code.'-COPY';
        for ($i = 2; $this->taken($this->query(), ['plan_code' => $code], null) && $i < 100; $i++) {
            $code = $source->plan_code.'-COPY-'.$i;
        }
        $copy['plan_code'] = $code;
        $copy['menus'] = PlanMenu::query()->where('plan_id', $source->id)->orderBy('sort_order')
            ->get(['event_menu_id', 'sort_order'])
            ->map(static fn (PlanMenu $m): array => ['menu_id' => $m->event_menu_id, 'sort_order' => $m->sort_order])
            ->all();

        return $this->create($copy, $actor);
    }

    /** v1 normaliseCode(): upper case, spaces to _, only A-Z 0-9 _ -. */
    public static function code(mixed $value): string
    {
        $code = strtoupper(trim((string) $value));
        $code = (string) preg_replace('/\s+/', '_', $code);

        return (string) preg_replace('/[^A-Z0-9_-]/', '', $code);
    }

    /**
     * @param  list<mixed>  $menus  [{menu_id|id, sort_order?}]
     */
    private function syncMenus(Plan $plan, array $menus): void
    {
        $incoming = [];
        foreach ($menus as $index => $menu) {
            $id = is_array($menu) ? Flag::id($menu['menu_id'] ?? $menu['id'] ?? null) : Flag::id($menu);
            if ($id !== null && ! isset($incoming[$id])) {
                $incoming[$id] = is_array($menu) && is_numeric($menu['sort_order'] ?? null) ? (int) $menu['sort_order'] : $index;
            }
        }
        foreach (EventMenu::query()->where('is_default', 1)->pluck('id') as $locked) {
            $incoming[$locked] ??= count($incoming);
        }

        $known = EventMenu::query()->whereKey(array_keys($incoming))->pluck('id')->all();
        $incoming = array_intersect_key($incoming, array_flip($known));

        DB::transaction(static function () use ($plan, $incoming): void {
            PlanMenu::query()->where('plan_id', $plan->id)->whereNotIn('event_menu_id', array_keys($incoming))->delete();
            foreach ($incoming as $menuId => $position) {
                PlanMenu::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'event_menu_id' => $menuId],
                    ['sort_order' => $position],
                );
            }
        });
    }

    /**
     * Limits: blank or "unlimited" = NULL, else a whole number >= 1.
     * Storage: 1..100 MB or GB, or both blank.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function limits(array $values): array
    {
        foreach (Plan::LIMITS as $key => $label) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            if (self::blank($values[$key])) {
                $values[$key] = null;

                continue;
            }
            if (! preg_match('/^\d+$/', trim((string) $values[$key])) || (int) $values[$key] < 1) {
                throw ApiException::badRequest("{$label} must be a whole number of 1 or more, or blank for unlimited.");
            }
            $values[$key] = (int) $values[$key];
        }

        if (! array_key_exists('storage_limit', $values) && ! array_key_exists('storage_unit', $values)) {
            return $values;
        }
        if (self::blank($values['storage_unit'] ?? null) || self::blank($values['storage_limit'] ?? null)) {
            $values['storage_limit'] = null;
            $values['storage_unit'] = null;

            return $values;
        }

        $unit = strtoupper(trim((string) $values['storage_unit']));
        if (! in_array($unit, ['MB', 'GB'], true)) {
            throw ApiException::badRequest('Storage unit must be MB or GB.');
        }
        $limit = trim((string) $values['storage_limit']);
        if (! preg_match('/^\d+$/', $limit) || (int) $limit < 1 || (int) $limit > self::STORAGE_MAX) {
            throw ApiException::badRequest('Storage Limit must be a whole number from 1 to '.self::STORAGE_MAX.', or Unlimited.');
        }
        $values['storage_limit'] = (int) $limit;
        $values['storage_unit'] = $unit;

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function badge(array $values): array
    {
        if (! array_key_exists('plan_badge_id', $values)) {
            return $values;
        }
        $raw = $values['plan_badge_id'];
        if ($raw === null || $raw === '' || $raw === 'null' || (is_numeric($raw) && (int) $raw === 0)) {
            $values['plan_badge_id'] = null;

            return $values;
        }
        $id = Flag::id($raw);
        if ($id === null || ! PlanBadge::query()->whereKey($id)->exists()) {
            throw ApiException::badRequest('Select a valid plan badge.');
        }
        $values['plan_badge_id'] = $id;

        return $values;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string|null}
     */
    private function reason(array $input, string $kind): array
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw ApiException::badRequest("A reason for {$kind} is required.");
        }
        if (! in_array($reason, self::REASONS[$kind], true)) {
            throw ApiException::badRequest("Select a valid {$kind} reason.");
        }
        $comments = trim((string) ($input['comments'] ?? ''));
        if (mb_strlen($comments) > 300) {
            throw ApiException::badRequest('Comments must be 300 characters or fewer.');
        }

        return [$reason, $comments === '' ? null : $comments];
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '' || strtolower(trim((string) $value)) === 'unlimited';
    }
}
