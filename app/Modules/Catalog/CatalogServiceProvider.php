<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\Approvals\ApprovalRegistry;
use App\Core\Support\ModuleServiceProvider;
use App\Modules\Catalog\Actions\EventCategoryWriter;
use App\Modules\Catalog\Actions\EventMenuWriter;
use App\Modules\Catalog\Actions\FoodPreferenceOptionWriter;
use App\Modules\Catalog\Actions\MenuWriter;
use App\Modules\Catalog\Actions\NotificationCategoryWriter;
use App\Modules\Catalog\Actions\NotificationTemplateWriter;
use App\Modules\Catalog\Actions\PlanBadgeWriter;
use App\Modules\Catalog\Actions\PlanTypeWriter;
use App\Modules\Catalog\Actions\PlanWriter;
use App\Modules\Catalog\Actions\RelationshipOptionWriter;

/**
 * Catalog: what clients buy and events are made of — event categories, event
 * menus, plans and their menu grants, plan badges, vendor plan types, the
 * notification catalogue and the guest registration dropdowns.
 *
 * Exposes: PlanGrants (cache version for plan -> menu grants).
 */
final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $approvals = $this->app->make(ApprovalRegistry::class);
        $approvals->register('event_categories', 'event_category', EventCategoryWriter::class);
        $approvals->register('event_menus', 'event_menu', EventMenuWriter::class);
        $approvals->register('subscription_plans', 'subscription_plan', PlanWriter::class);
        $approvals->register('plan_badges', 'plan_badge', PlanBadgeWriter::class);
        $approvals->register('plan_types', 'plan_type', PlanTypeWriter::class);
        $approvals->register('menus', 'menus', MenuWriter::class);
        $approvals->register('notification_categories', 'notification_category', NotificationCategoryWriter::class);
        $approvals->register('notification_templates', 'notification_template', NotificationTemplateWriter::class);
        $approvals->register('guest_relationship_options', 'guest_relationship_option', RelationshipOptionWriter::class);
        $approvals->register('guest_food_preference_options', 'guest_food_preference_option', FoodPreferenceOptionWriter::class);
    }
}
