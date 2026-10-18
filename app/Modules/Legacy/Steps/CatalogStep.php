<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;
use Illuminate\Support\Facades\DB;

/**
 * The platform catalogue: event categories and menus, plans (v1
 * subscription_plans) and their menus, vendor tiers (plan_types), badges,
 * the notification catalogue and the two guest dropdowns (merged into
 * guest_options, so their ids change).
 */
final class CatalogStep implements Step
{
    public function name(): string
    {
        return 'catalog';
    }

    public function run(Import $import): void
    {
        $this->categoriesAndMenus($import);
        $this->tiersAndPlans($import);
        $this->notifications($import);
        $this->guestOptions($import);
    }

    /**
     * Columns every catalogue row shares.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function base(array $r): array
    {
        return [
            'company_id' => $r['company_id'] ?? 1, ...Import::approval($r['is_active']),
            'created_by' => $r['created_by'] ?? null, 'updated_by' => $r['updated_by'] ?? null,
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'] ?? null),
        ];
    }

    private function categoriesAndMenus(Import $i): void
    {
        $rows = $i->rows('event_categories');
        $i->put('event_categories', 'event_categories', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'description' => $r['description'], 'icon' => $r['icon'], 'color' => $r['color'],
            'sort_order' => (int) $r['sort_order'], ...self::base($r),
        ], $rows), count($rows));
        $categories = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $rows = $i->rows('event_menus');
        $i->put('event_menus', 'event_menus', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'slug' => $r['slug'], 'remarks' => $r['remarks'], 'description' => $r['description'],
            'menu_group' => $r['menu_group'], 'event_category_id' => isset($categories[(int) $r['event_category_id']]) ? $r['event_category_id'] : null,
            'is_default' => Import::bit($r['is_default'], 0), 'icon' => $r['icon'], 'color' => $r['color'], 'sort_order' => (int) $r['sort_order'], ...self::base($r),
        ], $rows), count($rows));

        $rows = $i->rows('menus');
        $i->put('menus', 'menus', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'icon' => $r['icon'], 'icon_fill_color_light' => $r['icon_fill_color_light'],
            'icon_fill_color_dark' => $r['icon_fill_color_dark'], 'display_status' => Import::bit($r['display_status']), 'sort_order' => (int) $r['sort_order'],
            ...self::base($r),
        ], $rows), count($rows));
    }

    private function tiersAndPlans(Import $i): void
    {
        $rows = $i->rows('plan_types');
        $i->put('plan_types', 'plan_types', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'description' => $r['description'], 'price' => $r['price'] ?? 0, 'discounted_price' => $r['discounted_price'],
            'validity' => $r['validity'], 'features' => $r['features'], 'is_custom' => Import::bit($r['is_custom'], 0), 'vendor_id' => null,
            'label_color' => $r['label_color'], 'sort_order' => (int) $r['sort_order'], ...self::base($r),
        ], $rows), count($rows));
        // A custom tier's vendor is set once vendors exist (Vendors step).
        foreach ($rows as $r) {
            if ($r['vendor_id'] !== null) {
                $i->map('plan_type_vendor', (int) $r['id'], (int) $r['vendor_id']);
            }
        }

        $menus = DB::table('menus')->pluck('id')->flip()->all();
        $links = [];
        $read = 0;
        foreach ($rows as $r) {
            foreach (array_values(Import::list($r['menu_ids'])) as $position => $menu) {
                $read++;
                if (isset($menus[(int) $menu])) {
                    $links[] = ['plan_type_id' => $r['id'], 'menu_id' => (int) $menu, 'sort_order' => $position];
                }
            }
        }
        $i->link('plan_types.menu_ids', 'plan_type_menu', $links, $read);

        $rows = $i->rows('plan_badges');
        $i->put('plan_badges', 'plan_badges', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'text' => $r['text'], 'style' => $r['style'], 'color' => $r['color'], 'apply_to' => $r['apply_to'],
            'sort_order' => (int) $r['sort_order'], ...self::base($r),
        ], $rows), count($rows));
        $badges = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $tiers = DB::table('plan_types')->pluck('id')->flip()->all();
        $categories = DB::table('event_categories')->pluck('id')->flip()->all();

        $rows = $i->rows('subscription_plans');
        $i->put('subscription_plans', 'plans', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'plan_code' => $r['plan_code'],
            'plan_type_id' => isset($tiers[(int) $r['plan_type_id']]) ? $r['plan_type_id'] : null, 'billing_cycle' => $r['billing_cycle'],
            'short_description' => $r['short_description'], 'event_category_id' => isset($categories[(int) $r['event_category_id']]) ? $r['event_category_id'] : null,
            'max_events' => $r['max_events'], 'max_guests_per_event' => $r['max_guests_per_event'], 'max_rsvp_per_event' => $r['max_rsvp_per_event'],
            'max_photos' => $r['max_photos'], 'max_videos' => $r['max_videos'], 'storage_limit' => $r['storage_limit'], 'storage_unit' => $r['storage_unit'],
            'currency_code' => $r['currency_code'], 'price' => $r['price'], 'trial_days' => (int) $r['trial_days'], 'is_visible' => Import::bit($r['is_visible']),
            'plan_badge_id' => isset($badges[(int) $r['plan_badge_id']]) ? $r['plan_badge_id'] : null,
            'deactivation_reason' => $r['deactivation_reason'], 'deactivation_comments' => $r['deactivation_comments'],
            'deactivated_at' => Import::at($r['deactivated_at']), 'deactivated_by' => $r['deactivated_by'],
            'deletion_reason' => $r['deletion_reason'], 'deletion_comments' => $r['deletion_comments'], 'deleted_by' => $r['deleted_by'],
            'sort_order' => (int) $r['sort_order'], ...self::base($r),
        ], $rows), count($rows));

        $plans = DB::table('plans')->pluck('id')->flip()->all();
        $menus = DB::table('event_menus')->pluck('id')->flip()->all();
        $rows = $i->rows('subscription_plan_menus');
        $i->put('subscription_plan_menus', 'plan_menu', array_values(array_map(static fn (array $r): array => [
            'id' => $r['id'], 'plan_id' => $r['plan_id'], 'event_menu_id' => $r['menu_id'], 'sort_order' => (int) $r['sort_order'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']),
        ], array_filter($rows, static fn (array $r): bool => isset($plans[(int) $r['plan_id']], $menus[(int) $r['menu_id']])))), count($rows));

        $rows = $i->rows('plan_badge_plans');
        $i->link('plan_badge_plans', 'plan_badge_plan', array_values(array_map(static fn (array $r): array => ['plan_badge_id' => $r['badge_id'], 'plan_id' => $r['plan_id']],
            array_filter($rows, static fn (array $r): bool => isset($badges[(int) $r['badge_id']], $plans[(int) $r['plan_id']])))), count($rows));
    }

    private function notifications(Import $i): void
    {
        $rows = $i->rows('notification_categories');
        $i->put('notification_categories', 'notification_categories', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'description' => $r['description'], 'icon' => $r['icon'], 'color' => $r['color'],
            'sort_order' => (int) $r['sort_order'], ...self::base($r),
        ], $rows), count($rows));
        $categories = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $eventCategories = DB::table('event_categories')->pluck('id')->flip()->all();

        $rows = $i->rows('notification_templates');
        $keep = array_values(array_filter($rows, static fn (array $r): bool => isset($categories[(int) $r['notification_category_id']])));
        $i->put('notification_templates', 'notification_templates', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'trigger_key' => $r['trigger_key'], 'notification_category_id' => $r['notification_category_id'],
            'event_category_id' => isset($eventCategories[(int) $r['event_category_id']]) ? $r['event_category_id'] : null,
            'title' => $r['title'], 'content' => $r['content'], 'variables_used' => Import::json($r['variables_used']), 'image_url' => $r['image_url'],
            'channels' => Import::json($r['channels']) ?? '[]', 'sort_order' => (int) $r['sort_order'], ...self::base($r),
        ], $keep), count($rows));
    }

    /** Relationships first, then food preferences, in one table: new ids, recorded. */
    private function guestOptions(Import $i): void
    {
        $eventCategories = DB::table('event_categories')->pluck('id')->flip()->all();
        $next = 1;
        foreach (['guest_relationship_options' => 'relationship', 'guest_food_preference_options' => 'food_preference'] as $table => $kind) {
            $rows = $i->rows($table);
            $out = [];
            foreach ($rows as $r) {
                $i->map($table, (int) $r['id'], $next);
                $out[] = [
                    'id' => $next++, 'kind' => $kind, 'event_category_id' => isset($eventCategories[(int) $r['event_category_id']]) ? $r['event_category_id'] : null,
                    'name' => $r['name'], 'description' => $r['description'], 'icon' => $r['icon'], 'color' => $r['color'], 'sort_order' => (int) $r['sort_order'],
                    ...self::base($r),
                ];
            }
            $i->put($table, 'guest_options', $out, count($rows));
        }
    }
}
