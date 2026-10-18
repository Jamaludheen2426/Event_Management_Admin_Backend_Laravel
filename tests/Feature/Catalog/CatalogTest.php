<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Menu;
use App\Modules\Catalog\Models\NotificationCategory;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;

/*
| The catalogue screens: v1 paths, keys, messages and rules.
*/

beforeEach(function () {
    seedHouse();
    $this->token = accessToken(adminUser());
});

function menu(string $name, int $default = 0): EventMenu
{
    return EventMenu::query()->create(['company_id' => 1, 'name' => $name, 'slug' => strtolower($name), 'is_default' => $default]);
}

it('manages event categories with v1 keys, messages and default order', function () {
    $this->withToken($this->token)->postJson('/api/v1/event-categories', ['name' => ' Wedding ', 'sortOrder' => 2])
        ->assertCreated()
        ->assertJsonPath('message', 'Event category created successfully')
        ->assertJsonPath('data.eventCategory.name', 'Wedding')
        ->assertJsonPath('data.eventCategory.is_active', 1)
        ->assertJsonMissingPath('data.eventCategory.approval_status');
    $this->withToken($this->token)->postJson('/api/v1/event-categories', ['name' => 'Birthday', 'sort_order' => 1])->assertCreated();

    $this->withToken($this->token)->postJson('/api/v1/event-categories', ['name' => 'Wedding'])
        ->assertStatus(400)->assertJsonPath('message', 'An event category named "Wedding" already exists.');
    $this->withToken($this->token)->postJson('/api/v1/event-categories', ['name' => '  '])
        ->assertStatus(400)->assertJsonPath('message', 'Category name is required');

    $this->withToken($this->token)->getJson('/api/v1/event-categories')
        ->assertJsonPath('data.0.name', 'Birthday')
        ->assertJsonPath('data.1.name', 'Wedding')
        ->assertJsonPath('data.0.has_pending_approval', false);

    $id = EventCategory::query()->where('name', 'Wedding')->value('id');
    $this->withToken($this->token)->patchJson("/api/v1/event-categories/{$id}/status", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.eventCategory.is_active', 0);
    $this->withToken($this->token)->deleteJson("/api/v1/event-categories/{$id}")->assertOk();
    $this->withToken($this->token)->postJson('/api/v1/event-categories', ['name' => 'Wedding'])->assertCreated();
});

it('sends a catalogue change for approval when the role says so', function () {
    $clerk = giveRole(adminUser(role: null), customRole(['event_categories.create' => true], 50));

    $this->withToken(accessToken($clerk))->postJson('/api/v1/event-categories', ['name' => 'Conference'])
        ->assertStatus(202)->assertJsonPath('status', 'pending_approval');

    $this->withToken($this->token)->getJson('/api/v1/event-categories')->assertJsonPath('data.0.is_active', 2);
});

it('keeps the two guest dropdowns apart, each with its own response key', function () {
    $wedding = EventCategory::query()->create(['company_id' => 1, 'name' => 'Wedding']);

    $this->withToken($this->token)->postJson('/api/v1/guest-relationship-options', ['name' => 'Friend'])
        ->assertCreated()->assertJsonPath('data.relationshipOption.name', 'Friend')->assertJsonMissingPath('data.relationshipOption.kind');
    $this->withToken($this->token)->postJson('/api/v1/guest-food-preference-options', ['name' => 'Friend'])
        ->assertCreated()->assertJsonPath('data.foodPreferenceOption.name', 'Friend');
    $this->withToken($this->token)->postJson('/api/v1/guest-relationship-options', ['name' => 'Friend'])
        ->assertStatus(400)->assertJsonPath('message', '"Friend" is already in this list.');
    $this->withToken($this->token)->postJson('/api/v1/guest-relationship-options', ['name' => 'Friend', 'event_category_id' => $wedding->id])
        ->assertCreated()->assertJsonPath('data.relationshipOption.category.name', 'Wedding');
    $this->withToken($this->token)->postJson('/api/v1/guest-food-preference-options', ['name' => 'Veg', 'event_category_id' => 999])
        ->assertStatus(400)->assertJsonPath('message', 'That event category does not exist.');

    $this->withToken($this->token)->getJson('/api/v1/guest-relationship-options?event_category_id=null')->assertJsonPath('pagination.totalItems', 1);
    $this->withToken($this->token)->getJson('/api/v1/guest-relationship-options')->assertJsonPath('pagination.totalItems', 2);
    $this->withToken($this->token)->getJson('/api/v1/guest-food-preference-options')->assertJsonPath('pagination.totalItems', 1);

    $foodId = $this->withToken($this->token)->getJson('/api/v1/guest-food-preference-options')->json('data.0.id');
    $this->withToken($this->token)->getJson("/api/v1/guest-relationship-options/{$foodId}")
        ->assertStatus(404)->assertJsonPath('message', 'That relationship was not found.');
});

it('gives event menus unique slugs, grants Default menus to every plan, and guards the toggle field', function () {
    $plan = Plan::query()->create(['company_id' => 1, 'name' => 'Free', 'plan_code' => 'FREE']);

    $first = $this->withToken($this->token)->postJson('/api/v1/event-menus', ['name' => 'Guest List'])->json('data.eventMenu');
    $second = $this->withToken($this->token)->postJson('/api/v1/event-menus', ['name' => 'Guest List!'])->json('data.eventMenu');
    expect($first['slug'])->toBe('guest-list')->and($second['slug'])->toBe('guest-list-2');

    $this->withToken($this->token)->patchJson("/api/v1/event-menus/{$first['id']}/toggle/is_default", ['value' => true])
        ->assertOk()->assertJsonPath('data.eventMenu.is_default', 1);
    expect(PlanMenu::query()->where('plan_id', $plan->id)->where('event_menu_id', $first['id'])->exists())->toBeTrue();

    $this->withToken($this->token)->patchJson("/api/v1/event-menus/{$first['id']}/toggle/company_id", ['value' => 2])
        ->assertStatus(400)->assertJsonPath('message', 'Unknown toggle "company_id".');

    $this->withToken($this->token)->postJson("/api/v1/event-menus/{$first['id']}/duplicate")
        ->assertCreated()->assertJsonPath('data.eventMenu.name', 'Guest List (Copy)')->assertJsonPath('data.eventMenu.slug', 'guest-list-copy');

    $this->withToken($this->token)->patchJson('/api/v1/event-menus/reorder', ['items' => [['id' => $first['id'], 'sort_order' => 5]]])
        ->assertOk()->assertJsonPath('data.updated', 1);
});

it('creates plans with normalised codes and limits, and always keeps the locked menus', function () {
    $guests = menu('Guests', default: 1);
    $gallery = menu('Gallery');

    $plan = $this->withToken($this->token)->postJson('/api/v1/subscription-plans', [
        'name' => 'Gold', 'planCode' => 'gold plan', 'price' => '999', 'maxEvents' => '5', 'maxPhotos' => 'unlimited',
        'storageLimit' => 10, 'storageUnit' => 'gb', 'menus' => [['menuId' => $gallery->id]],
    ])->assertCreated()
        ->assertJsonPath('data.subscriptionPlan.plan_code', 'GOLD_PLAN')
        ->assertJsonPath('data.subscriptionPlan.price', '999.00')
        ->assertJsonPath('data.subscriptionPlan.max_events', 5)
        ->assertJsonPath('data.subscriptionPlan.max_photos', null)
        ->assertJsonPath('data.subscriptionPlan.storage_unit', 'GB')
        ->assertJsonPath('data.subscriptionPlan.total_menus', 2)
        ->assertJsonPath('data.subscriptionPlan.planMenus.0.menu_id', $gallery->id)
        ->assertJsonPath('data.subscriptionPlan.planMenus.1.menu.slug', 'guests')
        ->json('data.subscriptionPlan');

    // A screen that leaves the locked menu out must not remove it.
    $this->withToken($this->token)->putJson("/api/v1/subscription-plans/{$plan['id']}", ['menus' => []])
        ->assertOk()->assertJsonPath('data.subscriptionPlan.total_menus', 1)
        ->assertJsonPath('data.subscriptionPlan.planMenus.0.menu_id', $guests->id);

    $this->withToken($this->token)->postJson('/api/v1/subscription-plans', ['name' => 'Dup', 'plan_code' => 'GOLD_PLAN'])
        ->assertStatus(400)->assertJsonPath('message', 'Plan code "GOLD_PLAN" is already in use.');
    $this->withToken($this->token)->postJson('/api/v1/subscription-plans', ['name' => 'Bad', 'plan_code' => 'BAD', 'max_events' => '0'])
        ->assertStatus(400)->assertJsonPath('message', 'Max Events must be a whole number of 1 or more, or blank for unlimited.');
    $this->withToken($this->token)->postJson('/api/v1/subscription-plans', ['name' => 'Bad', 'plan_code' => 'BAD', 'storage_limit' => 500, 'storage_unit' => 'MB'])
        ->assertStatus(400)->assertJsonPath('message', 'Storage Limit must be a whole number from 1 to 100, or Unlimited.');

    $this->withToken($this->token)->postJson("/api/v1/subscription-plans/{$plan['id']}/duplicate")
        ->assertCreated()->assertJsonPath('data.subscriptionPlan.plan_code', 'GOLD_PLAN-COPY')
        ->assertJsonPath('data.subscriptionPlan.total_menus', 1);
});

it('deactivates and deletes plans only with a listed reason', function () {
    $plan = Plan::query()->create(['company_id' => 1, 'name' => 'Old', 'plan_code' => 'OLD']);

    $this->withToken($this->token)->getJson('/api/v1/subscription-plans/reasons')->assertJsonPath('data.reasons.deletion.0', 'Plan is not required');
    $this->withToken($this->token)->patchJson("/api/v1/subscription-plans/{$plan->id}/deactivate", ['reason' => 'Because'])
        ->assertStatus(400)->assertJsonPath('message', 'Select a valid deactivation reason.');
    $this->withToken($this->token)->patchJson("/api/v1/subscription-plans/{$plan->id}/deactivate", ['reason' => 'Other', 'comments' => 'Retired'])
        ->assertOk()->assertJsonPath('data.subscriptionPlan.is_active', 0)->assertJsonPath('data.subscriptionPlan.deactivation_comments', 'Retired');
    $this->withToken($this->token)->patchJson("/api/v1/subscription-plans/{$plan->id}/reactivate")
        ->assertOk()->assertJsonPath('data.subscriptionPlan.deactivation_reason', null);

    $this->withToken($this->token)->postJson("/api/v1/subscription-plans/{$plan->id}/delete", ['reason' => 'Duplicate plan'])
        ->assertOk()->assertJsonPath('message', 'Plan deleted successfully')->assertJsonPath('data.subscriptionPlan.deletion_reason', 'Duplicate plan');
    expect(Plan::withTrashed()->find($plan->id)->trashed())->toBeTrue();
});

it('links badges to selected plans and keeps the badge settings', function () {
    $plan = Plan::query()->create(['company_id' => 1, 'name' => 'Gold', 'plan_code' => 'GOLD']);

    $this->withToken($this->token)->postJson('/api/v1/plan-badges', ['text' => 'Most Popular', 'style' => 'soft', 'apply_to' => 'selected', 'plan_ids' => [$plan->id]])
        ->assertCreated()->assertJsonPath('data.planBadge.plan_ids', [$plan->id])->assertJsonPath('data.planBadge.plans.0.plan_code', 'GOLD');
    $this->withToken($this->token)->postJson('/api/v1/plan-badges', ['text' => str_repeat('x', 26)])
        ->assertStatus(400)->assertJsonPath('message', 'Badge text must be 25 characters or fewer.');

    $this->withToken($this->token)->getJson('/api/v1/plan-badges/settings')->assertJsonPath('data.settings', ['enabled' => true, 'position' => 'top_right']);
    $this->withToken($this->token)->putJson('/api/v1/plan-badges/settings', ['enabled' => false, 'position' => 'top_left'])
        ->assertJsonPath('data.settings', ['enabled' => false, 'position' => 'top_left']);
    $this->withToken($this->token)->getJson('/api/v1/plan-badges/recommended')->assertJsonCount(6, 'data.recommended');
    $this->withToken($this->token)->getJson('/api/v1/plan-badges/summary')->assertJsonPath('data.summary.total', 1);
});

it('works out template variables, allows one template per system trigger, and duplicates switched off', function () {
    $category = NotificationCategory::query()->create(['company_id' => 1, 'name' => 'Reminders']);
    $body = ['name' => 'Welcome', 'notification_category_id' => $category->id, 'title' => 'Hi {{guest_name}}',
        'content' => 'See you at {{ venue_name }} {{unknown}}', 'trigger_key' => 'welcome_invitation', 'channels' => ['push', 'sms']];

    $id = $this->withToken($this->token)->postJson('/api/v1/notification-templates', $body)
        ->assertCreated()
        ->assertJsonPath('data.template.variables_used', ['guest_name', 'venue_name'])
        ->assertJsonPath('data.template.channels', ['push'])
        ->assertJsonPath('data.template.notificationCategory.name', 'Reminders')
        ->json('data.template.id');

    $this->withToken($this->token)->postJson('/api/v1/notification-templates', [...$body, 'name' => 'Other'])
        ->assertStatus(400)->assertJsonPath('message', '"Welcome" already handles this system trigger. Clear it there first.');
    $this->withToken($this->token)->postJson("/api/v1/notification-templates/{$id}/duplicate")
        ->assertCreated()->assertJsonPath('data.template.is_active', 0)->assertJsonPath('data.template.trigger_key', null);
    $this->withToken($this->token)->getJson('/api/v1/notification-templates/variables')->assertJsonPath('data.variables.0.label', '{{guest_name}}');
});

it('keeps the menus a plan type unlocks as menu_ids', function () {
    $ads = Menu::query()->create(['company_id' => 1, 'name' => 'Ads']);

    $this->withToken($this->token)->postJson('/api/v1/plan-types', ['name' => 'Basic', 'price' => 10, 'menu_ids' => [$ads->id, 999]])
        ->assertCreated()->assertJsonPath('data.planType.menu_ids', [$ads->id])->assertJsonPath('data.planType.price', '10.00');
    $this->withToken($this->token)->patchJson("/api/v1/menus/{$ads->id}/display-status", ['display_status' => 0])
        ->assertOk()->assertJsonPath('data.menu.display_status', 0);
});
