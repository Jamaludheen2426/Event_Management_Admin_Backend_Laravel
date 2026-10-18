<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\EventCategoryController;
use App\Modules\Catalog\Http\Controllers\EventMenuController;
use App\Modules\Catalog\Http\Controllers\GuestOptionController;
use App\Modules\Catalog\Http\Controllers\MenuController;
use App\Modules\Catalog\Http\Controllers\NotificationCategoryController;
use App\Modules\Catalog\Http\Controllers\NotificationTemplateController;
use App\Modules\Catalog\Http\Controllers\PlanBadgeController;
use App\Modules\Catalog\Http\Controllers\PlanController;
use App\Modules\Catalog\Http\Controllers\PlanTypeController;
use Illuminate\Support\Facades\Route;

/*
| Signed-in admin routes (portal.admin). Paths and permission slugs are v1's.
| {id} only matches digits, so fixed paths (stats, reorder, settings...) are safe.
*/

/**
 * The standard v1 list screen: list, show, create, edit, status switch, delete.
 *
 * @param  array<string, string>  $defaults  route defaults (e.g. which guest option list)
 */
$crud = static function (string $path, string $controller, string $permission, array $defaults = []): void {
    Route::prefix($path)->controller($controller)->group(static function () use ($permission, $defaults): void {
        $routes = [
            Route::get('/', 'index')->middleware("perm:{$permission}.view"),
            Route::get('{id}', 'show')->middleware("perm:{$permission}.view"),
            Route::post('/', 'store')->middleware("perm:{$permission}.create"),
            Route::put('{id}', 'update')->middleware("perm:{$permission}.edit"),
            Route::patch('{id}/status', 'status')->middleware("perm:{$permission}.edit"),
            Route::delete('{id}', 'destroy')->middleware("perm:{$permission}.delete"),
        ];
        foreach ($routes as $route) {
            foreach ($defaults as $key => $value) {
                $route->defaults($key, $value);
            }
        }
    });
};

$crud('event-categories', EventCategoryController::class, 'event_categories');
$crud('notification-categories', NotificationCategoryController::class, 'notification_categories');
$crud('guest-relationship-options', GuestOptionController::class, 'guest_relationship_options', ['kind' => 'relationship']);
$crud('guest-food-preference-options', GuestOptionController::class, 'guest_food_preference_options', ['kind' => 'food_preference']);
$crud('plan-types', PlanTypeController::class, 'plan_types');

Route::prefix('menus')->controller(MenuController::class)->group(static function (): void {
    Route::patch('{id}/display-status', 'displayStatus')->middleware('perm:menus.edit');
});
$crud('menus', MenuController::class, 'menus');

Route::prefix('event-menus')->controller(EventMenuController::class)->group(static function (): void {
    Route::patch('reorder', 'reorder')->middleware('perm:event_menus.edit');
    Route::patch('{id}/toggle/{field}', 'toggle')->middleware('perm:event_menus.edit');
    Route::post('{id}/duplicate', 'duplicate')->middleware('perm:event_menus.create');
});
$crud('event-menus', EventMenuController::class, 'event_menus');

Route::prefix('subscription-plans')->controller(PlanController::class)->group(static function (): void {
    Route::get('reasons', 'reasons')->middleware('perm:subscription_plans.view');
    Route::patch('{id}/deactivate', 'deactivate')->middleware('perm:subscription_plans.edit');
    Route::patch('{id}/reactivate', 'reactivate')->middleware('perm:subscription_plans.edit');
    Route::post('{id}/delete', 'deleteWithReason')->middleware('perm:subscription_plans.delete');
    Route::post('{id}/duplicate', 'duplicate')->middleware('perm:subscription_plans.create');
});
$crud('subscription-plans', PlanController::class, 'subscription_plans');

Route::prefix('plan-badges')->controller(PlanBadgeController::class)->group(static function (): void {
    Route::get('settings', 'settings')->middleware('perm:plan_badges.view');
    Route::put('settings', 'updateSettings')->middleware('perm:plan_badges.edit');
    Route::get('summary', 'summary')->middleware('perm:plan_badges.view');
    Route::get('recommended', 'recommended')->middleware('perm:plan_badges.view');
});
$crud('plan-badges', PlanBadgeController::class, 'plan_badges');

Route::prefix('notification-templates')->controller(NotificationTemplateController::class)->group(static function (): void {
    Route::get('variables', 'variables')->middleware('perm:notification_templates.view');
    Route::get('system-triggers', 'systemTriggers')->middleware('perm:notification_templates.view');
    Route::post('{id}/duplicate', 'duplicate')->middleware('perm:notification_templates.create');
});
$crud('notification-templates', NotificationTemplateController::class, 'notification_templates');
