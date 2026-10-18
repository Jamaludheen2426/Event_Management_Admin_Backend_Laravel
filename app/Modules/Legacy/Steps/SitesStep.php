<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;
use App\Modules\Sites\Actions\SiteTranslations;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteSections;
use App\Modules\Vendors\Models\Vendor;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Both v1 website builders into the Sites tables.
 *
 *  - vendor_websites keep their ids; each company website becomes the house
 *    vendor's marketing site, numbered after them;
 *  - the single-row sections become site_settings rows;
 *  - every list section is numbered afresh (company rows first, then vendor
 *    rows), recorded per source table in legacy_id_map, and links between
 *    rows (menu → page, slide → slider, photo → album...) are re-pointed;
 *  - rows of v1's vendor tables that were soft-deleted are not imported,
 *    nor company rows of a website that is not the company's;
 *  - content translations are re-pointed to the new row ids; brand names
 *    (client and sponsor names, social labels, company and site name) are
 *    dropped; the key registry is rebuilt by a scan.
 */
final class SitesStep implements Step
{
    /** Single-row sections: v1 tables (company, vendor) => site_settings section. */
    private const SETTINGS = [
        'basic-information' => ['company_website_basic_information', 'vendor_website_basic_information'],
        'hero' => ['company_website_hero_sections', 'vendor_website_hero_sections'],
        'footer' => ['company_website_footer_settings', 'vendor_website_footer_settings'],
        'seo' => ['company_website_seo_settings', 'vendor_website_seo_settings'],
        'login' => ['company_website_login_settings', null],
        'theme' => ['company_website_theme_settings', null],
        'contact' => ['company_website_contact_settings', 'vendor_website_contact_settings'],
        'pricing' => ['company_website_pricing_settings', 'vendor_website_pricing_settings'],
    ];

    /** Translation section => the v1 company table its record_id pointed into. */
    private const TRANSLATED = [
        'testimonials' => 'company_website_testimonials', 'how-it-works' => 'company_website_how_it_works', 'faqs' => 'company_website_faqs',
        'templates' => 'company_templates', 'nav-menu' => 'company_website_menu_items', 'sliders' => 'company_website_slider_items',
        'gallery' => 'company_website_gallery_items', 'video-tutorials' => 'company_website_video_tutorials', 'pages' => 'company_website_pages',
        'template-categories' => 'company_template_categories', 'faq-categories' => 'company_website_faq_categories',
        'gallery-categories' => 'company_website_gallery_categories', 'contact-categories' => 'company_website_contact_categories',
        'video-tutorial-categories' => 'company_website_video_tutorial_categories',
        'video-tutorial-subcategories' => 'company_website_video_tutorial_subcategories',
        'video-tutorial-difficulty-levels' => 'company_website_video_tutorial_difficulty_levels',
        'video-tutorial-types' => 'company_website_video_tutorial_types', 'features' => 'company_website_features',
        'pricing-plans' => 'company_website_pricing_plans', 'pricing-features' => 'company_website_pricing_matrix_features',
        'highlights' => 'company_website_highlights', 'slider-settings' => 'company_website_sliders',
        'basic-information' => 'company_website_basic_information', 'hero-section' => 'company_website_hero_sections',
        'footer' => 'company_website_footer_settings', 'seo' => 'company_website_seo_settings', 'login-page' => 'company_website_login_settings',
        'contact-settings' => 'company_website_contact_settings', 'pricing-settings' => 'company_website_pricing_settings',
    ];

    /** Brand names are not translated any more (Phase 8). */
    private const DROPPED = ['clients' => true, 'sponsors' => true, 'social-links' => true];

    private const DROPPED_FIELDS = ['basic-information' => ['company_name'], 'footer' => ['company_name'], 'seo' => ['site_name']];

    /** @var array<int, int> company id => its marketing site id */
    private array $marketing = [];

    /** @var array<int, int> v1 company_websites id => marketing site id */
    private array $companyWebsites = [];

    public function __construct(private readonly SiteTranslations $translations) {}

    public function name(): string
    {
        return 'sites';
    }

    public function run(Import $import): void
    {
        $this->palettes($import);
        $this->sites($import);
        $this->settings($import);
        $this->lists($import);
        $this->showcaseAndTutorials($import);
        $this->translations($import);
    }

    private function palettes(Import $i): void
    {
        $rows = $i->rows('color_palettes');
        $i->put('color_palettes', 'color_palettes', array_map(static fn (array $r): array => [
            'id' => $r['id'], 'company_id' => $r['company_id'] ?? 1, 'name' => $r['name'], 'primary_bg_color' => $r['primary_bg_color'],
            'primary_text_color' => $r['primary_text_color'], 'secondary_text_color' => $r['secondary_text_color'], 'paragraph_color' => $r['paragraph_color'],
            ...Import::approval($r['is_active']), 'created_by' => $r['created_by'], 'updated_by' => $r['updated_by'],
            'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at']),
        ], $rows), count($rows));
    }

    private function sites(Import $i): void
    {
        $vendors = DB::table('vendors')->pluck('id')->flip()->all();
        $palettes = DB::table('color_palettes')->pluck('id')->flip()->all();
        $palette = static fn (mixed $id): ?int => is_numeric($id) && isset($palettes[(int) $id]) ? (int) $id : null;

        $rows = $i->rows('vendor_websites');
        $out = [];
        foreach ($rows as $r) {
            if (! isset($vendors[(int) $r['vendor_id']])) {
                continue;
            }
            $out[] = ['id' => $r['id'], 'vendor_id' => $r['vendor_id'], 'kind' => Site::VENDOR, 'slug' => $r['slug'],
                'custom_domain' => $r['custom_domain'] ?: null, 'status' => $r['status'], 'theme_id' => $r['theme_id'] === null ? null : (string) $r['theme_id'],
                'palette_id' => $palette($r['palette_id']), 'publish_version' => (int) $r['publish_version'], 'published_at' => Import::at($r['published_at']),
                'settings_json' => Import::json($r['settings_json']), 'is_active' => Import::bit($r['is_active']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => Import::at($r['deleted_at'])];
            $i->map('vendor_websites', (int) $r['id'], (int) $r['id']);
        }
        $i->put('vendor_websites', 'sites', $out, count($rows));

        $next = (int) max([0, ...array_column($out, 'id')]) + 1;
        $rows = $i->rows('company_websites');
        $out = [];
        foreach ($rows as $r) {
            $company = (int) $r['company_id'];
            if ($company !== (int) config('tenancy.house_company_id') || isset($this->marketing[$company])) {
                $i->note("company website {$r['id']} (company {$company}): only the house company's first website is imported");

                continue;
            }
            $this->marketing[$company] = $next;
            $this->companyWebsites[(int) $r['id']] = $next;
            $i->map('company_websites', (int) $r['id'], $next);
            $out[] = ['id' => $next++, 'vendor_id' => Vendor::houseId(), 'kind' => Site::MARKETING, 'slug' => $r['slug'],
                'custom_domain' => $r['custom_domain'] ?: null, 'status' => in_array($r['status'], ['draft', 'published', 'maintenance'], true) ? $r['status'] : 'published',
                'theme_id' => $r['theme_id'], 'palette_id' => $palette($r['palette_id']), 'publish_version' => 0, 'published_at' => Import::at($r['published_at']),
                'settings_json' => Import::json($r['settings_json']), 'is_active' => Import::bit($r['is_active']),
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at']), 'deleted_at' => null];
        }
        $i->put('company_websites', 'sites', $out, count($rows));
    }

    /**
     * The site a v1 company row belongs to (rows of another of its websites are stale).
     *
     * @param  array<string, mixed>  $r
     */
    private function companySite(array $r): ?int
    {
        $site = $this->marketing[(int) ($r['company_id'] ?? 1)] ?? null;
        if ($site !== null && array_key_exists('website_id', $r) && $r['website_id'] !== null && ($this->companyWebsites[(int) $r['website_id']] ?? null) !== $site) {
            return null;
        }

        return $site;
    }

    /** @param array<string, mixed> $r */
    private function vendorSite(Import $i, array $r): ?int
    {
        return $i->id('vendor_websites', $r['website_id']);
    }

    private function settings(Import $i): void
    {
        $next = 1;
        $out = [];
        $read = 0;
        foreach (self::SETTINGS as $section => [$companyTable, $vendorTable]) {
            $spec = SiteSections::SETTINGS[$section];
            foreach ([$companyTable => true, $vendorTable => false] as $table => $company) {
                if ($table === '') {
                    continue;
                }
                $taken = [];
                foreach ($i->rows($table, withDeleted: false) as $r) {
                    $read++;
                    $site = $company ? $this->companySite($r) : $this->vendorSite($i, $r);
                    if ($site === null) {
                        continue;
                    }
                    if (isset($taken[$site])) {
                        $i->note("{$table} {$r['id']}: a second row for the same site (v1 only ever read the first), left out");

                        continue;
                    }
                    $taken[$site] = true;
                    $data = [];
                    foreach (array_keys($spec['fields']) as $field) {
                        if (array_key_exists($field, $r)) {
                            $data[$field] = in_array($field, $spec['json'] ?? [], true) ? Import::list($r[$field]) ?: null : $r[$field];
                        }
                    }
                    foreach ($spec['flags'] ?? [] as $flag) {
                        if (array_key_exists($flag, $data)) {
                            $data[$flag] = Import::bit($data[$flag]);
                        }
                    }
                    $i->map($table, (int) $r['id'], $next);
                    $out[] = ['id' => $next++, 'site_id' => $site, 'section' => $section, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'is_active' => Import::bit($r['is_active'] ?? 1), 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
                }
            }
        }
        $i->put('*_settings / *_information / *_hero_sections', 'site_settings', $out, $read);
    }

    /**
     * One list section from its v1 tables: rows numbered afresh, recorded per
     * source table. $values returns the row's columns (site_id and id are
     * added), or null to leave it out; $unique names rows that may appear
     * once per site.
     *
     * @param  array<string, bool|null>  $sources  v1 table => company table?
     * @param  Closure(array<string, mixed>, bool): ?array<string, mixed>  $values
     * @param  (Closure(array<string, mixed>): string)|null  $unique
     */
    private function move(Import $i, string $target, array $sources, Closure $values, ?Closure $unique = null): void
    {
        foreach ($sources as $table => $company) {
            // v1's vendor tables soft-deleted; their deleted rows stay behind.
            $rows = $i->rows($table, withDeleted: $company);
            $out = [];
            foreach ($rows as $r) {
                $site = $company ? $this->companySite($r) : $this->vendorSite($i, $r);
                $row = $site === null ? null : $values($r, $company);
                if ($row === null) {
                    continue;
                }
                $key = $unique === null ? null : $site.'|'.$unique($row);
                if ($key !== null && isset($this->uniques[$target][$key])) {
                    $i->note("{$table} {$r['id']}: duplicate of an earlier row on the same site, skipped");

                    continue;
                }
                if ($key !== null) {
                    $this->uniques[$target][$key] = true;
                }
                // Numbering continues across sources of the same target.
                $id = $this->next[$target] = ($this->next[$target] ?? 0) + 1;
                $i->map($table, (int) $r['id'], $id);
                $out[] = ['id' => $id, 'site_id' => $site, ...$row];
            }
            $i->put($table, $target, $out, count($rows));
        }
    }

    /** @var array<string, array<string, true>> target => unique keys already taken */
    private array $uniques = [];

    /** @var array<string, int> target => last id given */
    private array $next = [];

    private function lists(Import $i): void
    {
        $stamps = static fn (array $r): array => ['created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        $int = static fn (mixed $v, int $fallback = 0): int => is_numeric($v) ? (int) $v : $fallback;

        $this->move($i, 'site_social_links', ['company_website_social_links' => true, 'vendor_website_social_links' => false],
            static fn (array $r): array => ['icon_key' => $r['icon_key'], 'icon_color' => $r['icon_color'], 'label' => Import::text($r['label'], 100),
                'url' => $r['url'], 'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);

        $this->move($i, 'site_pages', ['company_website_pages' => true, 'vendor_website_pages' => false],
            static fn (array $r, bool $company): array => ['page_type' => $r['page_type'] ?? 'custom', 'title' => $r['title'], 'slug' => mb_substr((string) $r['slug'], 0, 180),
                'content' => $r['content'], 'excerpt' => $r['excerpt'], 'seo_title' => $r['seo_title'], 'seo_description' => $r['seo_description'],
                'seo_keywords' => $r['seo_keywords'], 'og_image_url' => $r['og_image_url'], 'status' => $r['status'] ?? ($company ? 'published' : 'draft'),
                'sort_order' => $int($r['sort_order']), 'is_system' => Import::bit($r['is_system'], 0), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)],
            static fn (array $row): string => (string) $row['slug']);

        // Menu: written without parents first, then each item's parent once all exist.
        $this->move($i, 'site_menu_items', ['company_website_menu_items' => true, 'vendor_website_menu_items' => false],
            static fn (array $r, bool $company): array => ['parent_id' => null, 'label' => $r['label'], 'item_type' => $r['item_type'] ?? 'custom',
                'page_id' => $i->id($company ? 'company_website_pages' : 'vendor_website_pages', $r['page_id']), 'url' => $r['url'],
                'target' => $r['target'] ?? ($company ? '_self' : 'self'), 'sort_order' => $int($r['sort_order']), 'is_visible' => Import::bit($r['is_visible']),
                'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);
        foreach (['company_website_menu_items', 'vendor_website_menu_items'] as $table) {
            foreach ($i->rows($table, withDeleted: $table === 'company_website_menu_items') as $r) {
                $item = $i->id($table, $r['id']);
                $parent = $i->id($table, $r['parent_id']);
                if ($item !== null && $parent !== null) {
                    DB::table('site_menu_items')->where('id', $item)->update(['parent_id' => $parent]);
                }
            }
        }

        $this->move($i, 'site_blocks', ['company_website_ui_blocks' => true, 'vendor_website_ui_blocks' => false],
            static fn (array $r): array => ['page_slug' => '', 'block_key' => mb_substr((string) $r['block_key'], 0, 100), 'label' => $r['label'],
                'variant_key' => $r['variant_key'], 'is_visible' => Import::bit($r['is_visible']), 'sort_order' => $int($r['sort_order']),
                'config_json' => Import::json($r['config_json']), 'design_json' => Import::json($r['design_json']), 'mobile_json' => Import::json($r['mobile_json']),
                'is_active' => Import::bit($r['is_active']), ...$stamps($r)],
            static fn (array $row): string => $row['page_slug'].'|'.$row['block_key']);

        $this->move($i, 'site_sliders', ['company_website_sliders' => true, 'vendor_website_sliders' => false],
            static fn (array $r, bool $company): array => ['slider_type' => $r['slider_type'] ?? 'simple', 'title' => $r['title'],
                'slider_height' => $r['slider_height'] ?? 'medium', 'autoplay' => Import::bit($r['autoplay']), 'autoplay_speed' => $int($r['autoplay_speed'], 5000),
                'status' => $r['status'] ?? ($company ? 'active' : 'draft'), 'config_json' => Import::json($r['config_json']), 'is_active' => Import::bit($r['is_active']),
                ...$stamps($r)]);

        $this->move($i, 'site_slides', ['company_website_slider_items' => true, 'vendor_website_slider_items' => false],
            static function (array $r, bool $company) use ($i, $int, $stamps): ?array {
                $slider = $i->id($company ? 'company_website_sliders' : 'vendor_website_sliders', $r['slider_id']);

                return $slider === null ? null : ['slider_id' => $slider, 'title' => $r['title'], 'description' => $r['description'], 'image_url' => $r['image_url'],
                    'button_label' => $r['button_label'], 'button_page_id' => $r['button_page_id'] === null ? null : (string) $r['button_page_id'],
                    'button_url' => $r['button_url'], 'button_color' => $r['button_color'], 'button_text_color' => $r['button_text_color'],
                    'sort_order' => $int($r['sort_order']), 'status' => $r['status'] ?? ($company ? 'active' : 'draft'), 'is_active' => Import::bit($r['is_active']),
                    ...$stamps($r)];
            });

        $category = static fn (array $r): array => ['name' => $r['name'], 'slug' => $r['slug'], 'description' => $r['description'],
            'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)];
        $this->move($i, 'site_gallery_categories', ['company_website_gallery_categories' => true, 'vendor_website_gallery_categories' => false], $category);
        $this->move($i, 'site_gallery_items', ['company_website_gallery_items' => true, 'vendor_website_gallery_items' => false],
            static fn (array $r, bool $company): array => ['category_id' => $i->id($company ? 'company_website_gallery_categories' : 'vendor_website_gallery_categories', $r['category_id']),
                'event_name' => $r['event_name'], 'event_type' => $r['event_type'], 'city' => $r['city'], 'image_url' => $r['image_url'], 'alt_text' => $r['alt_text'],
                'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);

        $this->move($i, 'site_contact_categories', ['company_website_contact_categories' => true, 'vendor_website_contact_categories' => false], $category);
        $this->move($i, 'site_contact_messages', ['company_website_contact_messages' => true, 'vendor_website_contact_messages' => false],
            static fn (array $r, bool $company): array => ['category_id' => $i->id($company ? 'company_website_contact_categories' : 'vendor_website_contact_categories', $r['category_id']),
                'category_other' => $r['category_other'], 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'], 'subject' => $r['subject'],
                'message' => $r['message'], 'status' => $r['status'] ?? ($company ? 'unread' : 'new'), 'metadata_json' => Import::json($r['metadata_json']),
                'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);
        $dead = 0;
        $this->move($i, 'site_contact_social_links', ['vendor_website_contact_social_links' => false],
            static function (array $r) use ($i, $int, $stamps, &$dead): ?array {
                if (($link = $i->id('vendor_website_social_links', $r['social_link_id'])) === null) {
                    $dead++;

                    return null;
                }

                return ['social_link_id' => $link, 'sort_order' => $int($r['sort_order']), 'is_visible' => Import::bit($r['is_visible']),
                    'is_active' => Import::bit($r['is_active']), ...$stamps($r)];
            });
        if ($dead > 0) {
            // v1's bulk save deleted and re-created social links, leaving these pointing at gone rows;
            // the contact page then showed all social links, which it still does with none chosen.
            $i->note("{$dead} contact-page social links point at social links v1 had already deleted, left out");
        }

        $this->move($i, 'site_testimonials', ['company_website_testimonials' => true, 'vendor_website_testimonials' => false],
            static fn (array $r): array => ['customer_name' => $r['customer_name'], 'photo_url' => $r['photo_url'], 'event_name' => $r['event_name'],
                'feedback' => $r['feedback'], 'rating' => $r['rating'] ?? 5, 'show_rating' => Import::bit($r['show_rating']), 'is_featured' => Import::bit($r['is_featured'], 0),
                'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);

        $logo = static fn (string $type): Closure => static fn (array $r): array => ['type' => $type, 'name' => $r['name'], 'logo_url' => $r['logo_url'],
            'website_url' => $r['website_url'], 'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)];
        $this->move($i, 'site_logos', ['company_website_clients' => true, 'vendor_website_clients' => false], $logo('client'));
        // Sponsors continue the numbering after the clients.
        $this->move($i, 'site_logos', ['company_website_sponsors' => true, 'vendor_website_sponsors' => false], $logo('sponsor'));

        $this->move($i, 'site_features', ['company_website_features' => true],
            static fn (array $r): array => ['title' => $r['title'], 'short_description' => $r['short_description'], 'detailed_description' => $r['detailed_description'],
                'icon' => $r['icon'], 'custom_icon_url' => $r['custom_icon_url'], 'feature_image_url' => $r['feature_image_url'],
                'bullet_points_json' => Import::json($r['bullet_points_json']), 'show_in_menu' => Import::bit($r['show_in_menu']),
                'menu_order' => $int($r['menu_order'], 1), 'status' => $r['status'] ?? 'Active', 'sort_order' => $int($r['sort_order']),
                'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);

        $this->move($i, 'site_steps', ['company_website_how_it_works' => true],
            static fn (array $r): array => ['step_number' => $int($r['step_number'], 1), 'title' => $r['title'], 'description' => $r['description'],
                'highlight_title' => $r['highlight_title'], 'highlight_subtext' => $r['highlight_subtext'], 'icon' => $r['icon'],
                'illustration_url' => $r['illustration_url'], 'is_active' => Import::bit($r['is_active']), 'sort_order' => $int($r['sort_order']), ...$stamps($r)]);

        $this->move($i, 'site_pricing_plans', ['company_website_pricing_plans' => true, 'vendor_website_pricing_plans' => false],
            static fn (array $r): array => ['plan_name' => $r['plan_name'], 'subtitle' => $r['subtitle'] ?? '', 'target_type' => $r['target_type'] ?? 'individuals',
                'currency' => $r['currency'] ?? '₹', 'price_monthly' => $r['price_monthly'] ?? 0, 'price_yearly' => $r['price_yearly'] ?? 0,
                'period_label' => $r['period_label'] ?? '/Month', 'badge_text' => $r['badge_text'] ?? '', 'badge_style' => $r['badge_style'] ?? 'filled',
                'is_popular' => Import::bit($r['is_popular'], 0), 'features_json' => Import::json($r['features_json']), 'is_active' => Import::bit($r['is_active']),
                'sort_order' => $int($r['sort_order']), ...$stamps($r)]);

        $this->move($i, 'site_pricing_features', ['company_website_pricing_matrix_features' => true, 'vendor_website_pricing_matrix_features' => false],
            static fn (array $r): array => ['feature_name' => $r['feature_name'], 'icon' => $r['icon'] ?? 'Sparkles', 'description' => $r['description'],
                'category' => $r['category'] ?? 'General', 'plan_values_json' => Import::json($r['plan_values_json']), 'sort_order' => $int($r['sort_order']),
                'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);

        // FAQs: the builder's and the admin FAQ screen's, all on the marketing site.
        $faqCategory = static fn (array $r): array => ['name' => $r['name'], 'description' => $r['description'], 'icon' => $r['icon'] ?? 'HelpCircle',
            'color' => $r['color'] ?? '#7C3AED', 'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), 'approval_status' => 'approved',
            'created_by' => $r['created_by'] ?? null, 'updated_by' => $r['updated_by'] ?? null, ...$stamps($r)];
        $this->move($i, 'site_faq_categories', ['company_website_faq_categories' => true], $faqCategory);
        $this->move($i, 'site_faq_categories', ['faq_categories' => true], static fn (array $r): ?array => $r['deleted_at'] === null ? $faqCategory($r) : null);
        $faq = static fn (string $categories): Closure => static fn (array $r): ?array => ($r['deleted_at'] ?? null) !== null ? null : [
            'faq_category_id' => $i->id($categories, $r['faq_category_id']), 'question' => $r['question'], 'answer' => $r['answer'], 'tags' => $r['tags'] ?? null,
            'is_featured' => Import::bit($r['is_featured'] ?? 0, 0), 'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']),
            'approval_status' => 'approved', 'created_by' => $r['created_by'] ?? null, 'updated_by' => $r['updated_by'] ?? null, ...$stamps($r)];
        $this->move($i, 'site_faqs', ['company_website_faqs' => true], $faq('company_website_faq_categories'));
        $this->move($i, 'site_faqs', ['faqs' => true], $faq('faq_categories'));

        $this->move($i, 'site_highlights', ['company_website_highlights' => true],
            static fn (array $r): array => ['page_slug' => mb_substr((string) $r['page_slug'], 0, 100), 'instance' => $int($r['instance'], 1),
                'settings_json' => Import::json($r['settings_json']), ...$stamps($r)],
            static fn (array $row): string => $row['page_slug'].'|'.$row['instance']);
    }

    private function showcaseAndTutorials(Import $i): void
    {
        $stamps = static fn (array $r): array => ['created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        $int = static fn (mixed $v, int $fallback = 0): int => is_numeric($v) ? (int) $v : $fallback;

        $this->move($i, 'site_template_categories', ['company_template_categories' => true],
            static fn (array $r): array => ['name' => $r['name'], 'slug' => $r['slug'], 'description' => $r['description'], 'icon' => $r['icon'] ?? 'tag',
                'color' => $r['color'] ?? '#6A38F5', 'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);
        $this->move($i, 'site_templates', ['company_templates' => true],
            static fn (array $r): array => ['category_id' => $i->id('company_template_categories', $r['category_id']), 'template_name' => $r['template_name'],
                'slug' => $r['slug'], 'description' => $r['description'], 'template_type' => $r['template_type'] ?? 'wedding',
                'design_style' => $r['design_style'] ?? 'classic', 'primary_color' => $r['primary_color'] ?? '#6A38F5', 'thumbnail_url' => $r['thumbnail_url'],
                'template_file_url' => $r['template_file_url'], 'preview_url' => $r['preview_url'], 'is_active' => Import::bit($r['is_active']),
                'allow_customize' => Import::bit($r['allow_customize']), 'is_draft' => Import::bit($r['is_draft'], 0), 'is_popular' => Import::bit($r['is_popular'], 0),
                'sort_order' => $int($r['sort_order']), ...$stamps($r)]);

        // Four v1 taxonomy tables into one, told apart by kind.
        $taxonomy = static fn (string $kind): Closure => static fn (array $r): array => ['kind' => $kind,
            'parent_id' => $kind === 'subcategory' ? $i->id('company_website_video_tutorial_categories', $r['category_id']) : null,
            'name' => $r['name'], 'description' => $r['description'], 'icon' => $r['icon'], 'color' => $r['color'], 'sort_order' => $int($r['sort_order']),
            'is_active' => Import::bit($r['is_active']), ...$stamps($r)];
        $this->move($i, 'site_tutorial_taxonomies', ['company_website_video_tutorial_categories' => true], $taxonomy('category'));
        $this->move($i, 'site_tutorial_taxonomies', ['company_website_video_tutorial_subcategories' => true], $taxonomy('subcategory'));
        $this->move($i, 'site_tutorial_taxonomies', ['company_website_video_tutorial_difficulty_levels' => true], $taxonomy('difficulty'));
        $this->move($i, 'site_tutorial_taxonomies', ['company_website_video_tutorial_types' => true], $taxonomy('type'));

        $this->move($i, 'site_tutorials', ['company_website_video_tutorials' => true],
            static fn (array $r): array => ['title' => $r['title'], 'short_description' => $r['short_description'],
                'category_id' => $i->id('company_website_video_tutorial_categories', $r['category_id']),
                'subcategory_id' => $i->id('company_website_video_tutorial_subcategories', $r['subcategory_id']),
                'tags_json' => Import::json($r['tags_json']), 'video_source' => $r['video_source'] ?? 'upload', 'video_url' => $r['video_url'],
                'video_file_url' => $r['video_file_url'], 'duration_seconds' => $int($r['duration_seconds']),
                'difficulty_level_id' => $i->id('company_website_video_tutorial_difficulty_levels', $r['difficulty_level_id']),
                'tutorial_type_id' => $i->id('company_website_video_tutorial_types', $r['tutorial_type_id']), 'key_takeaways' => $r['key_takeaways'],
                'thumbnail_url' => $r['thumbnail_url'], 'is_featured' => Import::bit($r['is_featured'], 0), 'publish_date' => Import::at($r['publish_date']),
                'sort_order' => $int($r['sort_order']), 'is_active' => Import::bit($r['is_active']), ...$stamps($r)]);
    }

    /** Languages and translations of the marketing site, re-pointed to the new rows; then the key scan. */
    private function translations(Import $i): void
    {
        $rows = $i->rows('company_website_builder_languages');
        $out = [];
        foreach ($rows as $r) {
            $site = $this->marketing[(int) $r['company_id']] ?? null;
            if ($site !== null) {
                $i->map('company_website_builder_languages', (int) $r['id'], (int) $r['id']);
                $out[] = ['id' => $r['id'], 'site_id' => $site, 'code' => $r['code'], 'name' => $r['name'], 'native_name' => $r['native_name'],
                    'direction' => $r['direction'], 'is_default' => Import::bit($r['is_default'], 0), 'is_active' => Import::bit($r['is_active']),
                    'sort_order' => (int) $r['sort_order'], 'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
            }
        }
        $i->put('company_website_builder_languages', 'site_languages', $out, count($rows));

        $rows = $i->rows('company_website_content_translations');
        $out = [];
        $lost = [];
        foreach ($rows as $r) {
            $site = $this->marketing[(int) $r['company_id']] ?? null;
            $language = $i->id('company_website_builder_languages', $r['language_id']);
            $section = (string) $r['section'];
            if ($site === null || $language === null || isset(self::DROPPED[$section]) || in_array($r['field_key'], self::DROPPED_FIELDS[$section] ?? [], true)) {
                continue;
            }
            $record = $section === 'ui-chrome' ? 0 : (isset(self::TRANSLATED[$section]) ? $i->id(self::TRANSLATED[$section], $r['record_id']) : (int) $r['record_id']);
            if ($record === null) {
                $lost[$section] = ($lost[$section] ?? 0) + 1;

                continue;
            }
            $out[] = ['id' => $r['id'], 'site_id' => $site, 'section' => $section, 'page_slug' => (string) $r['page_slug'], 'record_id' => $record,
                'field_key' => $r['field_key'], 'language_id' => $language, 'value' => $r['value'], 'status' => $r['status'] ?? 'reviewed',
                'created_at' => Import::at($r['created_at']), 'updated_at' => Import::at($r['updated_at'])];
        }
        $i->put('company_website_content_translations', 'site_translations', $out, count($rows));
        foreach ($lost as $section => $n) {
            $i->note("{$n} translations of \"{$section}\" belong to rows that no longer exist, left out");
        }
        $i->note('translations of brand names (client and sponsor names, social labels, company and site name) left out on purpose');

        foreach ($this->marketing as $site) {
            $model = Site::query()->withoutGlobalScopes()->find($site);
            if ($model !== null) {
                $result = $this->translations->sync($model);
                $i->note("translation keys rebuilt from the content: {$result['discovered']} keys");
            }
        }
    }
}
