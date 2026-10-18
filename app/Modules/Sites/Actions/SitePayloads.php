<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Tenancy\Tenancy;
use App\Modules\Catalog\Models\PlanType;
use App\Modules\Sites\Models\ColorPalette;
use App\Modules\Sites\Models\Site;
use App\Modules\Vendors\Http\Resources\V1\VendorResource;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Support\Facades\Cache;

/**
 * Everything about one vendor site in a single answer: the builder app's
 * builder-data and preview payloads, and the vendor's public website.
 *
 * Built once per change of the site (the cache key follows Sites::version),
 * because it reads ~20 tables and production MySQL costs ~374 ms a query.
 */
final class SitePayloads
{
    /** Vendor fields the public website shows (v1 findActiveVendorBySlug's list; never bank details). */
    private const PUBLIC_VENDOR_FIELDS = [
        'id', 'company_id', 'company_name', 'company_logo', 'short_description', 'website', 'latitude', 'longitude', 'about_us',
        'company_information', 'company_contact', 'company_email', 'company_address', 'contact_mode', 'contact', 'alt_contact',
        'alt_email', 'address', 'alt_address', 'copywrite', 'poweredby', 'city_id', 'state_id', 'country_id', 'pincode_id',
        'district', 'locality', 'city',
    ];

    public function __construct(
        private readonly SiteContent $content,
        private readonly Sites $sites,
    ) {}

    /**
     * v1 getBuilderPayload: every section of the site, plus the admin's colour palettes.
     *
     * @return array<string, mixed>
     */
    public function builder(?Site $site, int $companyId): array
    {
        $payload = $site === null ? self::empty() : $this->sections($site);

        return ['colorPalettes' => $this->palettes($companyId), ...$payload];
    }

    /**
     * v1 getPreviewPayload: only what visitors would see — switched-off rows
     * dropped, and sections whose block is hidden emptied.
     *
     * @return array<string, mixed>
     */
    public function preview(?Site $site, int $companyId): array
    {
        $p = $this->builder($site, $companyId);
        $blocks = array_values(array_filter($p['uiBlocks'], self::enabled(...)));
        $byKey = [];
        foreach ($blocks as $block) {
            $byKey[(string) $block['block_key']] = $block;
        }
        $visible = static function (string $key, bool $fallback = true) use ($byKey): bool {
            $block = $byKey[$key] ?? null;
            if ($block === null) {
                return $fallback;
            }

            return ! empty(($block['config_json'] ?? [])['required_block'] ?? false) || self::shown($block);
        };

        $sliders = array_values(array_filter($p['sliders'], static function (array $slider) use ($visible): bool {
            if (! self::enabled($slider)) {
                return false;
            }
            $type = mb_strtolower((string) ($slider['slider_type'] ?? ''));

            return in_array($type, ['advanced', 'advance'], true)
                ? $visible('advance-slider', false)
                : $visible('basic-slider', $visible('simple-slider', false));
        }));
        $sliderIds = array_map(static fn (array $s): int => (int) $s['id'], $sliders);
        $enabledIf = static fn (bool $show, array $rows): array => $show ? array_values(array_filter($rows, self::enabled(...))) : [];
        $single = static fn (bool $show, ?array $row): ?array => $show && self::enabled($row) ? $row : null;

        return [
            'colorPalettes' => $p['colorPalettes'],
            'website' => self::enabled($p['website']) ? $p['website'] : null,
            'basicInformation' => $single($visible('basic-information'), $p['basicInformation']),
            'socialLinks' => array_values(array_filter($p['socialLinks'], self::enabled(...))),
            'pages' => array_values(array_filter($p['pages'], static fn (array $page): bool => self::enabled($page)
                && $visible('pages') && $visible('page:'.ltrim((string) ($page['slug'] ?? ''), '/')))),
            'menuItems' => $visible('nav-menu') ? array_values(array_filter($p['menuItems'], self::shown(...))) : [],
            'uiBlocks' => array_values(array_filter($blocks, static fn (array $b): bool => $visible((string) $b['block_key']))),
            'heroSection' => $single($visible('hero-section'), $p['heroSection']),
            'sliders' => $sliders,
            'sliderItems' => array_values(array_filter($p['sliderItems'], static fn (array $item): bool => self::enabled($item)
                && in_array((int) $item['slider_id'], $sliderIds, true))),
            'galleryCategories' => $enabledIf($visible('gallery-categories', false), $p['galleryCategories']),
            'galleryItems' => $enabledIf($visible('gallery-images', false), $p['galleryItems']),
            'contactSettings' => $single($visible('contact_us'), $p['contactSettings']),
            'contactSocialLinks' => $enabledIf($visible('contact_us'), $p['contactSocialLinks']),
            'contactCategories' => $enabledIf($visible('contact_us'), $p['contactCategories']),
            'testimonials' => $enabledIf($visible('testimonials', false), $p['testimonials']),
            'clients' => $enabledIf($visible('basic-clients', false), $p['clients']),
            'sponsors' => $enabledIf($visible('basic-sponsors', false), $p['sponsors']),
            'footer' => $single($visible('footer'), $p['footer']),
            'seo' => $single($visible('seo'), $p['seo']),
            'schemaReady' => true,
            'missingTables' => [],
        ];
    }

    /**
     * The vendor's public website (v1 /public/vendors/:slug/website-data):
     * found by its site's address, else by the vendor's company name.
     *
     * @return array<string, mixed>|null
     */
    public function publicWebsite(string $slug): ?array
    {
        $slug = mb_strtolower(trim($slug));
        $site = Tenancy::bypass(static fn (): ?Site => Site::query()->where('kind', Site::VENDOR)->where('is_active', 1)
            ->whereIn('status', ['published', 'draft'])
            ->where(static fn ($q) => $q->where('slug', $slug)->orWhere('custom_domain', $slug))->first());

        $vendorId = $site->vendor_id ?? Vendor::findActiveBySlug($slug)?->id;
        $vendor = $vendorId === null ? null : Tenancy::bypass(static fn (): ?Vendor => Vendor::query()->find($vendorId));
        if ($vendor === null) {
            return null;
        }
        $site ??= Tenancy::bypass(static fn (): ?Site => Site::query()->where('vendor_id', $vendor->id)->where('kind', Site::VENDOR)->first());

        $payload = Tenancy::bypass(fn (): array => $this->builder($site, (int) $vendor->company_id));
        $vendor->loadMissing(['owner', 'planType', 'district', 'city']);

        return [
            'vendor' => array_intersect_key((new VendorResource($vendor))->resolve(), array_flip(self::PUBLIC_VENDOR_FIELDS)),
            'colors' => ($payload['website']['settings_json'] ?? [])['colors'] ?? null,
            'sliders' => $payload['sliders'],
            'sliderItems' => $payload['sliderItems'],
            'heroSection' => $payload['heroSection'],
            'portfolio' => ['clients' => $payload['clients'], 'sponsors' => $payload['sponsors'], 'events' => []],
            'gallery' => $payload['galleryItems'],
            'galleryCategories' => $payload['galleryCategories'],
            'contactSettings' => $payload['contactSettings'],
            'contactSocialLinks' => $payload['contactSocialLinks'],
            'contactCategories' => $payload['contactCategories'],
            'testimonials' => $payload['testimonials'],
            'plans' => $this->plans((int) $vendor->company_id),
            'socialLinks' => $payload['socialLinks'],
            'pages' => $payload['pages'],
            'menuItems' => $payload['menuItems'],
            'uiBlocks' => $payload['uiBlocks'],
            'footer' => $payload['footer'],
            'seo' => $payload['seo'],
            'basicInformation' => $payload['basicInformation'],
            'terms_content' => self::pageContent($payload['pages'], 'terms-conditions'),
            'privacy_content' => self::pageContent($payload['pages'], 'privacy-policy'),
            'schemaReady' => true,
            'missingTables' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function sections(Site $site): array
    {
        return Cache::remember("sites:builder:{$site->id}:{$this->sites->version($site->id)}", now()->addHour(), fn (): array => [
            'website' => $this->sites->present($site),
            'basicInformation' => $this->content->setting($site, 'basic-information'),
            'socialLinks' => $this->content->rows($site, 'socialLinks'),
            'pages' => $this->content->rows($site, 'pages'),
            'menuItems' => $this->content->rows($site, 'menuItems'),
            'uiBlocks' => $this->content->blocks($site),
            'heroSection' => $this->content->setting($site, 'hero'),
            'sliders' => $this->content->rows($site, 'sliders'),
            'sliderItems' => $this->content->rows($site, 'slides'),
            'galleryCategories' => $this->content->rows($site, 'galleryCategories'),
            'galleryItems' => $this->content->rows($site, 'galleryItems'),
            'contactSettings' => $this->content->setting($site, 'contact'),
            'contactSocialLinks' => $this->content->rows($site, 'contactSocialLinks'),
            'contactCategories' => $this->content->rows($site, 'contactCategories'),
            'contactMessages' => $this->content->rows($site, 'contactMessages'),
            'testimonials' => $this->content->rows($site, 'testimonials'),
            'clients' => $this->content->rows($site, 'clients'),
            'sponsors' => $this->content->rows($site, 'sponsors'),
            'footer' => $this->content->setting($site, 'footer'),
            'seo' => $this->content->setting($site, 'seo'),
            'schemaReady' => true,
            'missingTables' => [],
        ]);
    }

    /** @return array<string, mixed> v1 defaultPayload(): no site yet */
    private static function empty(): array
    {
        return [
            'website' => null, 'basicInformation' => null, 'socialLinks' => [], 'pages' => [], 'menuItems' => [], 'uiBlocks' => [],
            'heroSection' => null, 'sliders' => [], 'sliderItems' => [], 'galleryCategories' => [], 'galleryItems' => [],
            'contactSettings' => null, 'contactSocialLinks' => [], 'contactCategories' => [], 'contactMessages' => [],
            'testimonials' => [], 'clients' => [], 'sponsors' => [], 'footer' => null, 'seo' => null,
            'schemaReady' => true, 'missingTables' => [],
        ];
    }

    /** @return list<array<string, mixed>> active palettes, oldest first (v1 getColorPalettes) */
    private function palettes(int $companyId): array
    {
        return Tenancy::bypass(static fn (): array => ColorPalette::query()->where('company_id', $companyId)->where('is_active', 1)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'name', 'primary_bg_color', 'primary_text_color', 'secondary_text_color', 'paragraph_color'])
            ->map(static fn (ColorPalette $p): array => $p->only(['id', 'name', 'primary_bg_color', 'primary_text_color', 'secondary_text_color', 'paragraph_color']))
            ->values()->all());
    }

    /** @return list<array<string, mixed>> the vendor tiers on offer (v1 PlanType is_active, not custom) */
    private function plans(int $companyId): array
    {
        return Tenancy::bypass(static fn (): array => PlanType::query()->where('company_id', $companyId)->where('is_active', 1)->where('is_custom', 0)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'price', 'discounted_price', 'features', 'label_color'])
            ->map(static fn (PlanType $p): array => $p->only(['id', 'name', 'price', 'discounted_price', 'features', 'label_color']))
            ->values()->all());
    }

    /** @param list<array<string, mixed>> $pages */
    private static function pageContent(array $pages, string $slug): string
    {
        foreach ($pages as $page) {
            if (($page['slug'] ?? null) === $slug) {
                return is_scalar($page['content'] ?? null) ? (string) $page['content'] : '';
            }
        }

        return '';
    }

    /**
     * v1 isEnabledRecord: blank is_active counts as on.
     *
     * @param  array<string, mixed>|null  $row
     */
    private static function enabled(?array $row): bool
    {
        return $row !== null && self::truthy($row['is_active'] ?? null);
    }

    /**
     * v1 isVisibleRecord.
     *
     * @param  array<string, mixed>|null  $row
     */
    private static function shown(?array $row): bool
    {
        return self::enabled($row) && self::truthy($row['is_visible'] ?? null);
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value) || is_int($value)) {
            return (bool) $value;
        }

        return ! in_array(mb_strtolower((string) $value), ['0', 'false', 'no', 'off'], true);
    }
}
