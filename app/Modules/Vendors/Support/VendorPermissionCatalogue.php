<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Support;

use Illuminate\Support\Str;

/**
 * Permissions a vendor can grant its staff (guard "vendor"): the v1 global
 * permission list (rows without a company), plus website_management.view the
 * staff portal checks. Vendor roles can only hold these.
 */
final class VendorPermissionCatalogue
{
    /** group slug => [label, actions] */
    private const GROUPS = [
        'dashboard' => ['Dashboard', ['view']],
        'client' => ['Client', ['view', 'create', 'edit', 'delete']],
        'staff' => ['Staff', ['view', 'create', 'edit', 'delete']],
        'roles' => ['Roles', ['view', 'create', 'edit', 'delete']],
        'modules' => ['Modules', ['view']],
        'event' => ['Event', ['view', 'create', 'edit', 'delete']],
        'communication' => ['Communication', ['view', 'send']],
        'payment' => ['Payment', ['view', 'edit']],
        'transactions' => ['Transactions', ['view']],
        'subscription' => ['Subscription', ['view', 'edit']],
        'reports' => ['Reports', ['view']],
        'settings' => ['Settings', ['view', 'edit']],
        'help' => ['Help', ['view']],
        'website_management' => ['Website Management', ['view']],
        'website_clients' => ['Clients', ['view', 'create', 'edit', 'delete']],
        'about' => ['About Company', ['view', 'edit']],
        'home' => ['Home', ['view', 'edit']],
        'home_slider' => ['Home Slider', ['view', 'create', 'edit', 'delete']],
        'events_section' => ['Events Section', ['view', 'edit']],
        'gallery' => ['Gallery', ['view', 'create', 'edit', 'delete']],
        'portfolio' => ['Portfolio', ['view', 'create', 'edit', 'delete']],
        'testimonial' => ['Testimonial', ['view', 'create', 'edit', 'delete']],
        'pages' => ['Pages', ['view', 'create', 'edit', 'delete']],
        'menu' => ['Menu', ['view', 'edit']],
        'footer' => ['Footer', ['view', 'edit']],
        'contact_us' => ['Contact Us', ['view', 'edit']],
    ];

    /** @return array<string, string> group slug => label */
    public static function groups(): array
    {
        return array_map(static fn (array $g): string => $g[0], self::GROUPS);
    }

    /** @return array<string, array{label: string, group: string}> slug => definition */
    public static function permissions(): array
    {
        $all = [];
        foreach (self::GROUPS as $group => [$label, $actions]) {
            foreach ($actions as $action) {
                $all["{$group}.{$action}"] = ['label' => Str::headline($action).' '.$label, 'group' => $group];
            }
        }

        return $all;
    }
}
