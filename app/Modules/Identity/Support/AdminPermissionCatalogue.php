<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Support\Str;

/**
 * Every permission the admin API checks (guard "admin"), grouped the way the
 * role screen shows them. Built from the slugs v1's admin routes and admin
 * screens use, so no route checks a permission that cannot be granted (the v1
 * database lacked many of them, leaving only super admins able to use those
 * screens).
 *
 * Vendor-portal permissions are a separate catalogue (guard "vendor", Phase 4).
 */
final class AdminPermissionCatalogue
{
    private const CRUD = ['view', 'create', 'edit', 'delete'];

    /**
     * Settings groups saved by POST /settings/bulk. Each has its own
     * "<group>_settings.edit" permission (v1 checked a settings permission
     * whose slug contains the group name).
     */
    public const SETTINGS_GROUPS = [
        'general', 'appearance', 'social_login', 'analytics', 'media', 'optimize', 'phone', 'pagination',
        'portal', 'app', 'common', 'stripe', 'paypal', 'razorpay', 'paystack', 'mollie', 'flutterwave',
        'twilio', 'recaptcha', 'google_maps', 'theme', 'email', 'storage', 'notification',
    ];

    /** @return array<string, array{label: string, actions: list<string>}> group slug => definition */
    public static function groups(): array
    {
        $groups = [
            'dashboard' => ['Dashboard', ['view']],
            'employees' => ['Employees', self::CRUD],
            'departments' => ['Departments', self::CRUD],
            'roles' => ['Roles', [...self::CRUD, 'manage']],
            'permissions' => ['Permissions', ['view', 'manage']],
            'modules' => ['Modules', ['view', 'manage']],
            'companies' => ['Companies', self::CRUD],
            'approvals' => ['Approvals', ['view']],
            'activity_logs' => ['Activity Logs', ['view', 'delete']],
            'settings' => ['Settings', ['view', 'edit']],
            'plugins' => ['Plugins', ['view', 'manage']],
            'languages' => ['Languages', self::CRUD],
            'currencies' => ['Currencies', self::CRUD],
            'locations' => ['Locations', self::CRUD],
            'media' => ['Media', ['view', 'upload', 'delete']],
            'translations' => ['Translations', [...self::CRUD, 'manage']],
            'email_configs' => ['Email Configs', [...self::CRUD, 'manage']],
            'email_templates' => ['Email Templates', ['view', 'read', 'create', 'update', 'delete', 'manage']],
            'email_campaigns' => ['Email Campaigns', ['read', 'create', 'update', 'delete', 'manage']],
            'vendors' => ['Vendors', [...self::CRUD, 'impersonate']],
            'website_clients' => ['Clients', self::CRUD],
            'event_categories' => ['Event Categories', self::CRUD],
            'event_menus' => ['Event Menus', self::CRUD],
            'event_templates' => ['Event Templates', self::CRUD],
            'template_categories' => ['Template Categories', self::CRUD],
            'frame_styles' => ['Frame Styles', self::CRUD],
            'decorations' => ['Decorations', self::CRUD],
            'color_palettes' => ['Color Palettes', self::CRUD],
            'notification_categories' => ['Notification Categories', self::CRUD],
            'notification_templates' => ['Notification Templates', self::CRUD],
            'subscription_plans' => ['Subscription Plans', self::CRUD],
            'plan_badges' => ['Plan Badges', self::CRUD],
            'plan_types' => ['Plan Types', self::CRUD],
            'menus' => ['Menus', self::CRUD],
            'website_builder' => ['Website Builder', ['view', 'edit']],
            'faqs' => ['FAQs', self::CRUD],
            'faq_categories' => ['FAQ Categories', self::CRUD],
            'guest_food_preference_options' => ['Guest Food Preferences', self::CRUD],
            'guest_relationship_options' => ['Guest Relationships', self::CRUD],
            'payments' => ['Payments', ['view', 'create', 'manage']],
            'mail' => ['Mail', ['view']],
            'reports' => ['Reports', ['view']],
        ];

        return array_map(
            static fn (array $group): array => ['label' => $group[0], 'actions' => $group[1]],
            $groups,
        );
    }

    /**
     * Every permission: slug => [label, group slug].
     *
     * @return array<string, array{label: string, group: string}>
     */
    public static function permissions(): array
    {
        $all = [];
        foreach (self::groups() as $group => ['label' => $label, 'actions' => $actions]) {
            foreach ($actions as $action) {
                $all["{$group}.{$action}"] = ['label' => Str::headline($action).' '.$label, 'group' => $group];
            }
        }

        foreach (self::SETTINGS_GROUPS as $settingsGroup) {
            $all["{$settingsGroup}_settings.edit"] = ['label' => 'Edit '.Str::headline($settingsGroup).' Settings', 'group' => 'settings'];
        }

        return $all;
    }
}
