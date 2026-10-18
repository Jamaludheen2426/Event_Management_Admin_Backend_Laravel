<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Catalog\Models\EventCategory;
use App\Modules\Catalog\Models\EventMenu;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanMenu;
use App\Modules\Catalog\Support\PlanGrants;
use App\Modules\Clients\Models\Client;
use App\Modules\Events\Models\Event;
use App\Modules\Templates\Models\Decoration;
use App\Modules\Templates\Models\EventTemplate;
use App\Modules\Templates\Models\TemplateFont;
use Illuminate\Support\Facades\Cache;

/**
 * What a client's plan lets them build (v1 clientPortal.getEventOptions):
 * the plan, its categories, the menus it grants, the invitation templates it
 * may use, fonts and time zones. The plan is the gatekeeper for every event
 * write; a menu the admin removes from a plan disappears from existing events.
 */
final class PlanEntitlements
{
    public const TIME_ZONES = [
        '(GMT -11:00) Midway Island, Samoa', '(GMT -10:00) Hawaii, Honolulu', '(GMT -09:00) Alaska, Anchorage',
        '(GMT -08:00) Pacific Time - US & Canada (Los Angeles)', '(GMT -07:00) Mountain Time - US & Canada (Denver, Phoenix)',
        '(GMT -06:00) Central Time - US & Canada (Chicago, Mexico City)', '(GMT -05:00) Eastern Time - US & Canada (New York, Toronto)',
        '(GMT -04:30) Caracas', '(GMT -04:00) Atlantic Time - Canada (Halifax), Santiago', '(GMT -03:30) Newfoundland',
        '(GMT -03:00) Brasilia, Buenos Aires, São Paulo', '(GMT -02:00) Mid-Atlantic', '(GMT -01:00) Azores, Cape Verde',
        '(GMT +00:00) Greenwich Mean Time, London, Dublin, Lisbon, UTC',
        '(GMT +01:00) Central European Time (Paris, Berlin, Rome, Madrid, Lagos)',
        '(GMT +02:00) Eastern European Time (Athens, Cairo, Johannesburg, Jerusalem)',
        '(GMT +03:00) Moscow, Riyadh, Kuwait, Nairobi, Baghdad', '(GMT +03:30) Tehran', '(GMT +04:00) Dubai, Abu Dhabi, Baku, Muscat',
        '(GMT +04:30) Kabul', '(GMT +05:00) Karachi, Islamabad, Tashkent, Yekaterinburg',
        '(GMT +05:30) India Standard Time (Mumbai, Kolkata, New Delhi, Colombo)', '(GMT +05:45) Kathmandu',
        '(GMT +06:00) Dhaka, Almaty, Astana', '(GMT +06:30) Yangon', '(GMT +07:00) Bangkok, Jakarta, Hanoi, Ho Chi Minh',
        '(GMT +08:00) Singapore, Beijing, Shanghai, Hong Kong, Taipei, Perth', '(GMT +09:00) Tokyo, Osaka, Seoul',
        '(GMT +09:30) Adelaide, Darwin', '(GMT +10:00) Sydney, Melbourne, Brisbane, Vladivostok, Guam',
        '(GMT +11:00) Solomon Islands, New Caledonia', '(GMT +12:00) Auckland, Wellington, Fiji', "(GMT +13:00) Nuku'alofa, Tonga",
    ];

    private const PLAN_COLUMNS = [
        'id', 'company_id', 'name', 'plan_code', 'billing_cycle', 'short_description', 'event_category_id', 'currency_code', 'price',
        'trial_days', 'is_active', 'max_events', 'max_guests_per_event', 'max_rsvp_per_event', 'max_photos', 'max_videos',
        'storage_limit', 'storage_unit',
    ];

    private const TAXONOMY = ['id', 'name', 'description', 'icon', 'color', 'sort_order', 'is_active'];

    private const TEMPLATE_COLUMNS = [
        'id', 'name', 'code', 'description', 'style', 'layout_style', 'thumbnail', 'background_type', 'background_color',
        'secondary_color', 'background_image', 'gradient_from', 'gradient_via', 'gradient_to', 'gradient_type',
        'gradient_direction', 'overlay_enabled', 'overlay_color', 'overlay_opacity', 'image_position', 'image_scale',
        'image_shape', 'corner_radius', 'background_position', 'image_size', 'orientation', 'dimension', 'primary_font',
        'secondary_font', 'border_style', 'primary_font_size', 'secondary_font_size', 'frame_color', 'decoration_color',
        'frame_style_id', 'components', 'component_order', 'event_category_id', 'template_category_id', 'is_featured',
        'sort_order', 'plan_availability', 'available_for',
    ];

    /**
     * The client's plan, or null with the reason v1 gave.
     *
     * @return array{0: ?Plan, 1: ?string}
     */
    public function planOf(Client $client): array
    {
        if ($client->plan_id === null) {
            return [null, 'No subscription plan is assigned to your account yet. Please contact us.'];
        }
        $plan = Plan::query()->withoutGlobalScopes()->find($client->plan_id, self::PLAN_COLUMNS);
        if ($plan === null) {
            return [null, 'Your subscription plan is no longer available. Please contact us.'];
        }
        if ((int) $plan->is_active !== 1) {
            return [$plan, 'Your subscription plan is inactive. Please contact us.'];
        }

        return [$plan, null];
    }

    /**
     * The wizard's whole catalogue for this client's plan.
     *
     * @return array<string, mixed>
     */
    public function options(Client $client): array
    {
        [$plan, $reason] = $this->planOf($client);
        $empty = ['categories' => [], 'menus' => [], 'portal_sections' => [], 'app_features' => [], 'templates' => []];
        if ($plan === null) {
            return ['plan' => null, 'reason' => $reason, ...$empty];
        }
        if ($reason !== null) {
            return ['plan' => $plan->toArray(), 'reason' => $reason, ...$empty];
        }

        $menus = $this->grantedMenus($plan);

        return [
            'plan' => $plan->toArray(),
            'events_used' => Event::query()->withTrashed()->where('client_id', $client->id)->count(),
            'reason' => $menus === [] ? 'Your plan does not include any menus yet. Please contact us.' : null,
            'categories' => EventCategory::query()->withoutGlobalScopes()->where('company_id', $plan->company_id)->where('is_active', 1)
                ->when($plan->getAttribute('event_category_id') !== null, static fn ($q) => $q->whereKey($plan->getAttribute('event_category_id')))
                ->orderBy('sort_order')->orderBy('id')->get(self::TAXONOMY)->toArray(),
            'menus' => $menus,
            'portal_sections' => array_column($menus, 'slug'),
            'app_features' => [],
            'templates' => $this->templates($plan),
            'fonts' => TemplateFont::query()->withoutGlobalScopes()->where('company_id', $plan->company_id)->where('is_active', 1)
                ->orderBy('name')->get(['id', 'name', 'source', 'link_url'])
                ->map(static fn (TemplateFont $f): array => [
                    ...$f->only(['id', 'name', 'source', 'link_url']),
                    'link_kind' => $f->source === 'link' ? (TemplateFont::formatOf($f->link_url) !== null ? 'file' : 'stylesheet') : null,
                ])->all(),
            'timezones' => self::TIME_ZONES,
        ];
    }

    /**
     * Active menus the plan grants, in catalogue order (cached until grants change).
     *
     * @return list<array<string, mixed>>
     */
    public function grantedMenus(Plan $plan): array
    {
        return Cache::remember(PlanGrants::key('menus:'.$plan->id), 300, static function () use ($plan): array {
            $ids = PlanMenu::query()->where('plan_id', $plan->id)->pluck('event_menu_id');

            return EventMenu::query()->withoutGlobalScopes()->whereIn('id', $ids)->where('is_active', 1)
                ->orderBy('sort_order')->orderBy('id')
                ->get([...self::TAXONOMY, 'slug', 'menu_group', 'is_default', 'event_category_id'])
                ->toArray();
        });
    }

    /** The plan limit (null = unlimited). */
    public function limit(?Plan $plan, string $key): ?int
    {
        $value = $plan?->getAttribute($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Published templates this plan may use, with their frame and decorations.
     *
     * @return list<array<string, mixed>>
     */
    private function templates(Plan $plan): array
    {
        $category = $plan->getAttribute('event_category_id');
        $hasTrial = (int) $plan->trial_days > 0;

        return EventTemplate::query()->withoutGlobalScopes()
            ->where('company_id', $plan->company_id)->where('is_active', 1)->where('status', 'published')
            ->when($category !== null, static fn ($q) => $q->where(static fn ($w) => $w->whereNull('event_category_id')->orWhere('event_category_id', $category)))
            ->with([
                'templateCategory' => static fn ($q) => $q->withoutGlobalScopes()->where('is_active', 1)->select(['id', 'name', 'sort_order']),
                'frameStyle' => static fn ($q) => $q->withoutGlobalScopes()->where('is_active', 1)->select(['id', 'name', 'file_url']),
                'decorations' => static fn ($q) => $q->withoutGlobalScopes()->where('decorations.is_active', 1),
                'plans:id',
            ])
            ->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('id')
            ->get(self::TEMPLATE_COLUMNS)
            ->filter(static function (EventTemplate $t) use ($plan, $hasTrial): bool {
                if (is_array($t->available_for) && $t->available_for === []) {
                    return false;
                }

                return match ($t->plan_availability) {
                    'selected' => $t->plans->contains('id', $plan->id),
                    'trial' => $hasTrial,
                    default => true,
                };
            })
            ->map(static function (EventTemplate $t): array {
                $data = $t->only(array_diff(self::TEMPLATE_COLUMNS, ['plan_availability', 'available_for']));

                return [
                    ...$data,
                    'decoration_ids' => $t->decorations->pluck('id')->all(),
                    'templateCategory' => $t->templateCategory?->only(['id', 'name', 'sort_order']),
                    'frame_url' => $t->frameStyle?->getAttribute('file_url'),
                    'decorationItems' => $t->decorations->map(static fn (Decoration $d): array => $d->only(['id', 'name', 'type', 'file_url']))->values()->all(),
                ];
            })
            ->values()->all();
    }
}
