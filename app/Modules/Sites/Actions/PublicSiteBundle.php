<?php

declare(strict_types=1);

namespace App\Modules\Sites\Actions;

use App\Core\Tenancy\Tenancy;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteHighlight;
use App\Modules\Sites\Models\SiteSetting;
use App\Modules\Vendors\Models\Vendor;
use Illuminate\Support\Facades\Cache;
use stdClass;

/**
 * The public site app's read model (v1 companyPublicSite.service): a visitor's
 * Host names the site — a slug under a public-site root domain
 * (PUBLIC_SITE_ROOT_DOMAINS: acme.eventinvit.app → "acme") or a custom domain
 * — and the whole site comes back in one answer, cached until it changes.
 *
 * Any site can be served this way now: the marketing site and every vendor's.
 */
final class PublicSiteBundle
{
    /** Single-row sections: bundle key => setting. */
    private const SETTINGS = [
        'basic_information' => 'basic-information', 'theme_settings' => 'theme', 'footer' => 'footer', 'seo' => 'seo',
        'contact_settings' => 'contact', 'login_settings' => 'login', 'pricing_settings' => 'pricing',
    ];

    /** List sections: bundle key => [section, only switched-on rows (v1)]. */
    private const LISTS = [
        'social_links' => ['socialLinks', false], 'pages' => ['pages', false], 'menu_items' => ['menuItems', false],
        'sliders' => ['sliders', false], 'slider_items' => ['slides', false], 'gallery_categories' => ['galleryCategories', false],
        'gallery_items' => ['galleryItems', false], 'contact_categories' => ['contactCategories', false],
        'testimonials' => ['testimonials', false], 'clients' => ['clients', false], 'sponsors' => ['sponsors', false],
        'features' => ['features', false], 'pricing_plans' => ['pricingPlans', false], 'pricing_matrix_features' => ['pricingFeatures', false],
        'faqs' => ['faqs', true], 'faq_categories' => ['faqCategories', true], 'how_it_works' => ['steps', true],
        'templates' => ['templates', true], 'template_categories' => ['templateCategories', true],
        'video_tutorials' => ['tutorials', true], 'video_tutorial_categories' => ['tutorialCategories', true],
    ];

    public function __construct(
        private readonly Sites $sites,
        private readonly SiteContent $content,
        private readonly SiteTranslations $translations,
    ) {}

    /** The site a visitor's host (or ?slug=, ?company_id=) names. */
    public function resolve(mixed $host, mixed $slug, mixed $companyId): ?Site
    {
        return Tenancy::bypass(function () use ($host, $slug, $companyId): ?Site {
            $query = Site::query();
            if (is_numeric($companyId) && (int) $companyId > 0) {
                // The company's own (marketing) site.
                $query->where('kind', Site::MARKETING)->whereIn('vendor_id', Vendor::query()->where('company_id', (int) $companyId)->select('id'));
            } elseif (is_string($slug) && $slug !== '') {
                $query->where('slug', self::normalize($slug));
            } else {
                $parsed = self::parse(is_string($host) ? $host : '');
                if ($parsed === null) {
                    return null;
                }
                $query->where($parsed[0], $parsed[1]);
            }

            return $query->orderBy('id')->first();
        });
    }

    /** @return array<string, mixed> v1 /public/site/resolve when found */
    public function identity(Site $site): array
    {
        return [
            'found' => true,
            'id' => $site->id,
            'company_id' => $this->companyId($site),
            'slug' => $site->slug,
            'custom_domain' => $site->custom_domain,
            'status' => $site->status,
            'is_published' => $site->status === 'published' && (bool) $site->is_active,
        ];
    }

    /**
     * Everything needed to render any page, plus the overlay for $language
     * (none for the default language).
     *
     * @return array<string, mixed>
     */
    public function bundle(Site $site, ?string $language): array
    {
        $language = $language !== null && $language !== '' ? mb_strtolower($language) : null;
        $key = "sites:bundle:{$site->id}:{$this->sites->version($site->id)}:".($language ?? '-');

        return Cache::remember($key, now()->addMinutes(30), fn (): array => $this->build($site, $language));
    }

    /** @return array<string, mixed> */
    private function build(Site $site, ?string $language): array
    {
        $settings = SiteSetting::query()->where('site_id', $site->id)->get()->keyBy('section');
        $translations = $language === null ? null : $this->translations->bundle($site, null, $language);

        $bundle = [
            'found' => true,
            'site' => [
                'id' => $site->id,
                'company_id' => $this->companyId($site),
                'slug' => $site->slug,
                'custom_domain' => $site->custom_domain,
                'status' => $site->status,
                'theme_id' => $site->theme_id,
                'palette_id' => $site->palette_id,
                'settings' => $site->settings_json ?? new stdClass,
            ],
            'hero_sections' => $this->heroByPage($site, $settings->get('hero')),
            'highlights' => SiteHighlight::query()->where('site_id', $site->id)->orderBy('id')->get()
                ->map(static fn (SiteHighlight $h): array => SiteContent::highlight($h))->values()->all(),
            'languages' => $this->translations->publicLanguages($site),
            'translations' => $translations === null
                ? ['language' => null, 'translations' => new stdClass]
                : [...$translations, 'translations' => $translations['translations'] === [] ? new stdClass : $translations['translations']],
            'ui_blocks' => $this->content->allBlocks($site),
        ];

        foreach (self::SETTINGS as $key => $section) {
            $row = $settings->get($section);
            $bundle[$key] = $row === null ? new stdClass : $this->content->presentSetting($site, $row);
        }
        foreach (self::LISTS as $key => [$section, $activeOnly]) {
            $rows = $this->content->rows($site, $section);
            if ($activeOnly) {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['is_active'] ?? 1) === 1));
                // The public site lists these by position, oldest first on ties (v1).
                usort($rows, static fn (array $a, array $b): int => [(int) ($a['sort_order'] ?? 0), (int) $a['id']] <=> [(int) ($b['sort_order'] ?? 0), (int) $b['id']]);
            }
            $bundle[$key] = $rows;
        }

        return $bundle;
    }

    /**
     * Hero copy per page: each page's override (in design_json) over the base
     * fields, keeping the row id its translations hang off; the base alone is
     * the home page when no override exists.
     *
     * @return array<string, array<string, mixed>>|stdClass
     */
    private function heroByPage(Site $site, ?SiteSetting $hero): array|stdClass
    {
        if ($hero === null) {
            return new stdClass;
        }
        $row = $this->content->presentSetting($site, $hero);
        $pages = is_array($row['design_json'] ?? null) ? $row['design_json'] : [];
        unset($row['design_json']);

        $merged = [];
        foreach ($pages as $page => $override) {
            if (is_array($override)) {
                $merged[(string) $page] = [...$row, ...$override, 'id' => $row['id'], 'page_slug' => (string) $page];
            }
        }
        $merged['home'] ??= [...$row, 'page_slug' => 'home'];

        return $merged;
    }

    private function companyId(Site $site): int
    {
        return (int) Tenancy::bypass(static fn () => Vendor::query()->whereKey($site->vendor_id)->value('company_id'));
    }

    /**
     * Host → [column, value]: a slug under a root domain, else a custom
     * domain; null for a root domain itself.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parse(string $host): ?array
    {
        $host = self::normalize($host);
        if ($host === '') {
            return null;
        }
        foreach ((array) config('clients.public_site_root_domains') as $root) {
            $root = self::normalize((string) $root);
            if ($root === '') {
                continue;
            }
            if ($host === $root) {
                return null;
            }
            if (str_ends_with($host, ".{$root}")) {
                $label = substr($host, 0, -strlen(".{$root}"));

                // Only one label is a site's slug; deeper names are not ours.
                return $label === '' || str_contains($label, '.') ? null : ['slug', $label];
            }
        }

        return ['custom_domain', $host];
    }

    /** Lower case, first of a forwarded list, no port, no "www.". */
    private static function normalize(string $host): string
    {
        $host = explode(',', mb_strtolower(trim($host)))[0];

        return (string) preg_replace('/^www\./', '', explode(':', trim($host))[0]);
    }
}
