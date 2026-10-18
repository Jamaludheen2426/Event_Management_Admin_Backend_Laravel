<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Core\Exceptions\ApiException;
use App\Core\Support\Flag;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteContactCategory;
use App\Modules\Sites\Models\SiteContactMessage;
use App\Modules\Sites\Models\SiteContactSocialLink;
use App\Modules\Sites\Models\SiteFaq;
use App\Modules\Sites\Models\SiteFaqCategory;
use App\Modules\Sites\Models\SiteFeature;
use App\Modules\Sites\Models\SiteGalleryCategory;
use App\Modules\Sites\Models\SiteGalleryItem;
use App\Modules\Sites\Models\SiteLogo;
use App\Modules\Sites\Models\SiteMenuItem;
use App\Modules\Sites\Models\SitePage;
use App\Modules\Sites\Models\SitePricingFeature;
use App\Modules\Sites\Models\SitePricingPlan;
use App\Modules\Sites\Models\SiteRecord;
use App\Modules\Sites\Models\SiteSlide;
use App\Modules\Sites\Models\SiteSlider;
use App\Modules\Sites\Models\SiteSocialLink;
use App\Modules\Sites\Models\SiteStep;
use App\Modules\Sites\Models\SiteTemplate;
use App\Modules\Sites\Models\SiteTemplateCategory;
use App\Modules\Sites\Models\SiteTestimonial;
use App\Modules\Sites\Models\SiteTutorial;
use App\Modules\Sites\Models\SiteTutorialTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What every section of a site is: the list sections (one table each) and the
 * single-row sections (site_settings), with v1's defaults, the alternative
 * input names v1 accepted, and v1's messages.
 *
 * Where the two v1 builders disagreed (column defaults, ENUM columns that only
 * the vendor builder had), the site's kind decides.
 */
final class SiteSections
{
    /**
     * Single-row sections: fields with their default values (the marketing
     * site's, from v1's company tables), the vendor site's where v1's vendor
     * tables differed, and the ENUMs only those had.
     *
     * @var array<string, array{fields: array<string, mixed>, json?: list<string>, flags?: list<string>, vendor?: array<string, mixed>, enums?: array<string, list<string>>, aliases?: array<string, string>}>
     */
    public const SETTINGS = [
        'basic-information' => [
            'fields' => [
                'company_name' => null, 'city' => null, 'logo_url' => null, 'header_color' => null, 'contact_type' => 'default',
                'mobile_country_code' => null, 'mobile' => null, 'email' => null, 'address' => null, 'social_links_json' => null,
                'show_social_icons' => 1, 'show_login' => 1, 'show_signin' => 1,
            ],
            'json' => ['social_links_json'],
            'flags' => ['show_social_icons', 'show_login', 'show_signin'],
            'vendor' => ['header_color' => '#FFFFFF'],
            'enums' => ['contact_type' => ['default', 'alternative']],
            'aliases' => ['show_sign_in' => 'show_signin'],
        ],
        'hero' => [
            'fields' => [
                'image_url' => null, 'badge_text' => null, 'title' => null, 'description' => null, 'hero_height' => 'medium',
                'overlay_enabled' => 1, 'overlay_color' => '#000000', 'overlay_opacity' => 60, 'button_1_json' => null,
                'button_2_json' => null, 'button_layout' => 'left', 'content_alignment' => 'left', 'mobile_settings_json' => null,
                'design_json' => null,
            ],
            'json' => ['button_1_json', 'button_2_json', 'mobile_settings_json', 'design_json'],
            'flags' => ['overlay_enabled'],
            'vendor' => ['overlay_color' => null],
            'aliases' => ['btn1' => 'button_1_json', 'button1' => 'button_1_json', 'btn2' => 'button_2_json', 'button2' => 'button_2_json', 'content_align' => 'content_alignment'],
        ],
        'footer' => [
            'fields' => [
                'logo_url' => null, 'company_name' => null, 'description' => null, 'contact_type' => 'default', 'mobile' => null,
                'email' => null, 'address' => null, 'top_list_json' => null, 'top_list_heading' => null, 'top_list_heading_2' => 'Company',
                'show_quick_links_1' => 1, 'show_quick_links_2' => 1, 'quick_links_json' => null, 'quick_links_2_json' => null,
                'add_pages_json' => null, 'show_newsletter' => 1, 'show_social_links' => 1, 'copyright_text' => null,
                'powered_by_text' => null, 'locked_fields_json' => null, 'design_json' => null,
            ],
            'json' => ['top_list_json', 'quick_links_json', 'quick_links_2_json', 'add_pages_json', 'locked_fields_json', 'design_json'],
            'flags' => ['show_quick_links_1', 'show_quick_links_2', 'show_newsletter', 'show_social_links'],
            'enums' => ['contact_type' => ['default', 'alternative']],
            'aliases' => [
                'short_description' => 'description', 'newsletter_enabled' => 'show_newsletter', 'selected_pages' => 'add_pages_json',
                'copyright' => 'copyright_text', 'powered_by' => 'powered_by_text',
            ],
        ],
        'seo' => [
            'fields' => [
                'default_title' => null, 'default_description' => null, 'default_keywords' => null, 'author' => null, 'language' => 'en',
                'site_name' => null, 'og_title' => null, 'og_description' => null, 'og_image_url' => null,
                'twitter_card' => 'summary_large_image', 'canonical_url' => null, 'robots_index' => 1, 'robots_follow' => 1,
                'sitemap_enabled' => 1, 'structured_data_enabled' => 1, 'schema_json' => null, 'tracking_json' => null,
            ],
            'json' => ['schema_json', 'tracking_json'],
            'flags' => ['robots_index', 'robots_follow', 'sitemap_enabled', 'structured_data_enabled'],
            'vendor' => ['language' => null, 'twitter_card' => null, 'structured_data_enabled' => 0],
            'aliases' => [
                'meta_title' => 'default_title', 'meta_description' => 'default_description', 'keywords' => 'default_keywords',
                'og_image' => 'og_image_url', 'structured_data' => 'structured_data_enabled',
            ],
        ],
        'login' => [
            'fields' => ['title' => null, 'subtitle' => null, 'logo_url' => null, 'bg_image_url' => null, 'show_social_login' => 1, 'allow_register' => 1],
            'flags' => ['show_social_login', 'allow_register'],
        ],
        'theme' => [
            'fields' => [
                'primary_color' => '#FF4D8D', 'secondary_color' => '#F97316', 'accent_color' => '#8B5CF6', 'background_color' => '#FFFFFF',
                'text_color' => '#111827', 'font_family' => 'Inter', 'border_radius' => '8px',
            ],
        ],
        'contact' => [
            'fields' => [
                'mode' => 'default', 'email' => null, 'mobile' => null, 'address' => null, 'contact_form_enabled' => 1,
                'social_links_enabled' => 1, 'google_map_enabled' => 1, 'latitude' => null, 'longitude' => null,
            ],
            'flags' => ['contact_form_enabled', 'social_links_enabled', 'google_map_enabled'],
            'vendor' => ['mode' => 'static'],
            'enums' => ['mode' => ['static', 'dynamic']],
        ],
        'pricing' => [
            'fields' => [
                'section_title' => 'Choose the Perfect Plan for Your Needs', 'section_subtitle' => null,
                'badge_text' => 'Simple, Transparent & Affordable Pricing', 'individual_heading' => 'For Individuals',
                'individual_subheading' => 'Perfect for creating beautiful events for personal occasions',
                'company_heading' => 'For Event Management Companies',
                'company_subheading' => 'Powerful tools to manage multiple events and clients seamlessly',
                'yearly_discount_badge' => 'Save up to 20%',
            ],
        ],
    ];

    /** @var array<string, Section> */
    private array $built = [];

    public function list(string $key): Section
    {
        return $this->built[$key] ??= $this->make($key);
    }

    /** @return array<string, mixed> a single-row section's defaults on this site */
    public static function settingDefaults(string $section, Site $site): array
    {
        $spec = self::SETTINGS[$section] ?? throw ApiException::notFound('Section not found');

        $overrides = $site->isMarketing() ? [] : ($spec['vendor'] ?? []);

        return array_merge($spec['fields'], $overrides);
    }

    private function make(string $key): Section
    {
        $vendorStatus = static fn (array $v, Site $site): array => $site->isMarketing() ? $v : self::vendorSlideStatus($v);

        return match ($key) {
            'socialLinks' => new Section(SiteSocialLink::class,
                aliases: ['icon' => 'icon_key', 'color' => 'icon_color'],
                messages: ['listed' => 'Social links retrieved', 'saved' => 'Social links saved'],
                folder: 'website-builder/social-icons'),

            'pages' => new Section(SitePage::class,
                vendorEnums: ['page_type' => ['system', 'custom', 'legal'], 'status' => ['draft', 'published']],
                defaults: static fn (Site $site): array => ['page_type' => 'custom', 'status' => $site->isMarketing() ? 'published' : 'draft'],
                messages: ['listed' => 'Pages retrieved', 'created' => 'Page created', 'updated' => 'Page updated', 'deleted' => 'Page deleted',
                    'unique' => 'A page with this slug already exists.'],
                folder: 'website-builder/pages'),

            'menuItems' => new Section(SiteMenuItem::class,
                references: ['parent_id' => SiteMenuItem::class, 'page_id' => SitePage::class],
                vendorEnums: ['item_type' => ['home', 'page', 'service', 'events', 'gallery', 'contact', 'custom'], 'target' => ['self', 'blank']],
                defaults: static fn (Site $site): array => $site->isMarketing()
                    ? ['item_type' => 'custom', 'target' => '_self']
                    : ['item_type' => 'page', 'target' => 'self'],
                messages: ['listed' => 'Menu items retrieved', 'saved' => 'Menu items saved', 'created' => 'Menu item created',
                    'updated' => 'Menu item updated', 'deleted' => 'Menu item deleted'],
                folder: 'website-builder/menu-items'),

            'sliders' => new Section(SiteSlider::class,
                order: [['id', 'desc']],
                vendorEnums: ['slider_type' => ['simple', 'advanced'], 'status' => ['draft', 'published']],
                defaults: static fn (Site $site): array => ['status' => $site->isMarketing() ? 'active' : 'draft'],
                messages: ['listed' => 'Sliders retrieved', 'created' => 'Slider created', 'updated' => 'Slider updated', 'deleted' => 'Slider deleted'],
                folder: 'website-builder/sliders'),

            'slides' => new Section(SiteSlide::class,
                aliases: ['button_page' => 'button_page_id'],
                references: ['slider_id' => SiteSlider::class],
                vendorEnums: ['status' => ['draft', 'published']],
                normalize: $vendorStatus,
                defaults: static fn (Site $site): array => ['status' => $site->isMarketing() ? 'active' : 'draft'],
                messages: ['listed' => 'Slider items retrieved', 'saved' => 'Slider items saved', 'created' => 'Slider item created',
                    'updated' => 'Slider item updated', 'deleted' => 'Slider item deleted'],
                folder: 'website-builder/sliders'),

            'galleryCategories' => new Section(SiteGalleryCategory::class,
                aliases: ['order' => 'sort_order'],
                normalize: self::slugFrom('name'),
                messages: ['listed' => 'Gallery categories retrieved', 'created' => 'Gallery category created',
                    'updated' => 'Gallery category updated', 'deleted' => 'Gallery category deleted'],
                folder: 'website-builder/gallery'),

            'galleryItems' => new Section(SiteGalleryItem::class,
                references: ['category_id' => SiteGalleryCategory::class],
                messages: ['listed' => 'Gallery items retrieved', 'created' => 'Gallery item created',
                    'updated' => 'Gallery item updated', 'deleted' => 'Gallery item deleted'],
                folder: 'website-builder/gallery'),

            'contactCategories' => new Section(SiteContactCategory::class,
                aliases: ['order' => 'sort_order'],
                normalize: self::slugFrom('name'),
                messages: ['listed' => 'Contact categories retrieved', 'created' => 'Contact category created',
                    'updated' => 'Contact category updated', 'deleted' => 'Contact category deleted'],
                folder: 'website-builder/contact'),

            'contactMessages' => new Section(SiteContactMessage::class,
                order: [['id', 'desc']],
                aliases: ['metadata' => 'metadata_json'],
                references: ['category_id' => SiteContactCategory::class],
                defaults: static fn (Site $site): array => ['status' => $site->isMarketing() ? 'unread' : 'new'],
                messages: ['listed' => 'Contact messages retrieved', 'created' => 'Contact message created',
                    'updated' => 'Contact message updated', 'deleted' => 'Contact message deleted'],
                folder: 'website-builder/contact'),

            'contactSocialLinks' => new Section(SiteContactSocialLink::class,
                references: ['social_link_id' => SiteSocialLink::class],
                messages: ['listed' => 'Contact social links retrieved', 'saved' => 'Contact social links saved'],
                folder: 'website-builder/contact'),

            'testimonials' => new Section(SiteTestimonial::class,
                messages: ['listed' => 'Testimonials retrieved', 'created' => 'Testimonial created', 'updated' => 'Testimonial updated',
                    'deleted' => 'Testimonial deleted', 'saved' => 'Testimonials saved'],
                folder: 'website-builder/testimonials'),

            'clients' => new Section(SiteLogo::class,
                fixed: ['type' => 'client'],
                hidden: ['type'],
                messages: ['listed' => 'Clients retrieved', 'created' => 'Client created', 'updated' => 'Client updated',
                    'deleted' => 'Client deleted', 'saved' => 'Clients saved'],
                folder: 'website-builder/clients'),

            'sponsors' => new Section(SiteLogo::class,
                fixed: ['type' => 'sponsor'],
                hidden: ['type'],
                messages: ['listed' => 'Sponsors retrieved', 'created' => 'Sponsor created', 'updated' => 'Sponsor updated',
                    'deleted' => 'Sponsor deleted', 'saved' => 'Sponsors saved'],
                folder: 'website-builder/sponsors'),

            'features' => new Section(SiteFeature::class,
                aliases: ['image_url' => 'feature_image_url'],
                defaults: static fn (Site $site, array $input): array => [
                    'icon' => 'calendar', 'status' => 'Active', 'menu_order' => 1, 'bullet_points_json' => [],
                    'show_in_menu' => Flag::bit($input['show_in_menu'] ?? null, 0),
                ],
                messages: ['listed' => 'Features retrieved', 'created' => 'Feature created', 'updated' => 'Feature updated',
                    'deleted' => 'Feature deleted', 'saved' => 'Features saved', 'status' => 'Feature status updated successfully'],
                folder: 'website-builder/features'),

            'steps' => new Section(SiteStep::class,
                defaults: static function (Site $site, array $input): array {
                    $number = Flag::id($input['step_number'] ?? null) ?? SiteStep::query()->where('site_id', $site->id)->count() + 1;

                    return ['step_number' => $number, 'title' => 'New Step', 'icon' => 'gift', 'sort_order' => $number];
                },
                messages: ['listed' => 'How It Works steps retrieved', 'created' => 'Step created successfully',
                    'updated' => 'Step updated successfully', 'deleted' => 'Step deleted successfully',
                    'saved' => 'Steps list saved successfully', 'status' => 'Step status updated successfully'],
                folder: 'website-builder/how-it-works'),

            'pricingPlans' => new Section(SitePricingPlan::class,
                defaults: static fn (): array => ['features_json' => []],
                messages: ['listed' => 'Pricing plans retrieved', 'created' => 'Pricing plan created', 'updated' => 'Pricing plan updated',
                    'deleted' => 'Pricing plan deleted successfully', 'saved' => 'Pricing plans saved',
                    'status' => 'Pricing plan status updated successfully', 'missing' => 'Pricing plan not found'],
                folder: 'website-builder/pricing'),

            'pricingFeatures' => new Section(SitePricingFeature::class,
                aliases: ['title' => 'feature_name', 'plan_availability' => 'plan_values_json'],
                defaults: static fn (): array => ['feature_name' => 'Feature', 'description' => '', 'plan_values_json' => (object) []],
                messages: ['listed' => 'Pricing matrix features retrieved', 'created' => 'Pricing matrix feature created',
                    'updated' => 'Pricing matrix feature updated', 'deleted' => 'Pricing matrix feature deleted',
                    'saved' => 'Pricing matrix features saved', 'missing' => 'Pricing matrix feature not found'],
                folder: 'website-builder/pricing'),

            'faqCategories' => new Section(SiteFaqCategory::class,
                defaults: static fn (): array => ['name' => 'New Category'],
                hidden: ['approval_status', 'created_by', 'updated_by'],
                messages: ['listed' => 'FAQ Categories retrieved', 'created' => 'FAQ Category created successfully',
                    'updated' => 'FAQ Category updated successfully', 'deleted' => 'FAQ Category deleted successfully',
                    'status' => 'FAQ category status updated successfully'],
                folder: 'website-builder/faqs'),

            'faqs' => new Section(SiteFaq::class,
                order: [['sort_order', 'asc'], ['id', 'desc']],
                references: ['faq_category_id' => SiteFaqCategory::class],
                defaults: static fn (): array => ['question' => '', 'answer' => ''],
                filter: static function (Builder $q, Request $r): void {
                    self::search($q, $r, ['question', 'answer', 'tags']);
                    self::whereChosen($q, $r, 'category_id', 'faq_category_id');
                    self::whereActive($q, $r);
                },
                present: static function (array $row, SiteRecord $faq): array {
                    $category = $faq->getRelation('category');

                    return [...$row,
                        'category_name' => $category?->getAttribute('name'),
                        'category_icon' => $category?->getAttribute('icon'),
                        'category_color' => $category?->getAttribute('color'),
                        'category' => $category === null ? null : ['id' => $category->getKey(), ...$category->only(['name', 'icon', 'color'])],
                    ];
                },
                hidden: ['approval_status', 'created_by', 'updated_by'],
                messages: ['listed' => 'FAQs retrieved', 'shown' => 'FAQ details retrieved', 'created' => 'FAQ created successfully',
                    'updated' => 'FAQ updated successfully', 'deleted' => 'FAQ deleted successfully',
                    'status' => 'FAQ status updated successfully', 'missing' => 'FAQ not found'],
                folder: 'website-builder/faqs',
                with: ['category']),

            'templateCategories' => new Section(SiteTemplateCategory::class,
                normalize: self::slugFrom('name'),
                messages: ['listed' => 'Template categories retrieved', 'created' => 'Template category created',
                    'updated' => 'Template category updated', 'deleted' => 'Template category deleted',
                    'status' => 'Template category status updated successfully'],
                folder: 'website-builder/templates'),

            'templates' => new Section(SiteTemplate::class,
                references: ['category_id' => SiteTemplateCategory::class],
                normalize: self::slugFrom('template_name'),
                filter: static function (Builder $q, Request $r): void {
                    self::whereChosen($q, $r, 'category_id', 'category_id');
                    self::whereChosen($q, $r, 'template_type', 'template_type');
                    self::search($q, $r, ['template_name', 'description']);
                },
                present: static fn (array $row, SiteRecord $t): array => [...$row, 'category_name' => $t->getRelation('category')?->getAttribute('name')],
                messages: ['listed' => 'Templates retrieved', 'shown' => 'Template details retrieved', 'created' => 'Template created',
                    'updated' => 'Template updated', 'deleted' => 'Template deleted', 'status' => 'Template status updated successfully',
                    'missing' => 'Template not found'],
                folder: 'website-builder/templates',
                with: ['category']),

            'tutorialCategories' => self::taxonomy('category', ['name' => 'New Category', 'icon' => 'video', 'color' => '#7C3AED'],
                'Video tutorial categories retrieved', 'Video tutorial category'),
            'tutorialSubcategories' => self::taxonomy('subcategory', ['name' => 'New Sub Category', 'icon' => 'file-text', 'color' => '#7C3AED'],
                'Video tutorial sub categories retrieved', 'Video tutorial sub category'),
            'tutorialDifficulties' => self::taxonomy('difficulty', ['name' => 'New Level', 'icon' => 'bar-chart', 'color' => '#22C55E'],
                'Difficulty levels retrieved', 'Difficulty level'),
            'tutorialTypes' => self::taxonomy('type', ['name' => 'New Type', 'icon' => 'list', 'color' => '#F97316'],
                'Tutorial types retrieved', 'Tutorial type'),

            'tutorials' => new Section(SiteTutorial::class,
                order: [['sort_order', 'asc'], ['id', 'desc']],
                references: [
                    'category_id' => SiteTutorialTaxonomy::class, 'subcategory_id' => SiteTutorialTaxonomy::class,
                    'difficulty_level_id' => SiteTutorialTaxonomy::class, 'tutorial_type_id' => SiteTutorialTaxonomy::class,
                ],
                normalize: static function (array $v): array {
                    // v1 took the tags as an array, or as "a, b" in tags or tags_json.
                    $tags = $v['tags_json'] ?? $v['tags'] ?? null;
                    unset($v['tags']);
                    if (is_string($tags)) {
                        $v['tags_json'] = array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $t): bool => $t !== ''));
                    } elseif (is_array($tags)) {
                        $v['tags_json'] = array_values($tags);
                    }

                    return $v;
                },
                defaults: static fn (): array => ['title' => 'New Tutorial', 'video_source' => 'upload', 'tags_json' => []],
                filter: static function (Builder $q, Request $r): void {
                    self::search($q, $r, ['title', 'short_description']);
                    foreach (['category_id', 'subcategory_id', 'difficulty_level_id', 'tutorial_type_id'] as $column) {
                        self::whereChosen($q, $r, $column, $column);
                    }
                    self::whereActive($q, $r);
                },
                present: static fn (array $row, SiteRecord $t): array => [...$row,
                    'category_name' => $t->getRelation('category')?->getAttribute('name'),
                    'subcategory_name' => $t->getRelation('subcategory')?->getAttribute('name'),
                    'difficulty_name' => $t->getRelation('difficulty')?->getAttribute('name'),
                    'difficulty_color' => $t->getRelation('difficulty')?->getAttribute('color'),
                    'type_name' => $t->getRelation('type')?->getAttribute('name'),
                ],
                messages: ['listed' => 'Video tutorials retrieved', 'shown' => 'Video tutorial details retrieved',
                    'created' => 'Video tutorial created successfully', 'updated' => 'Video tutorial updated successfully',
                    'deleted' => 'Video tutorial deleted successfully', 'status' => 'Video tutorial status updated successfully',
                    'missing' => 'Video tutorial not found'],
                folder: 'website-builder/video-tutorials',
                with: ['category', 'subcategory', 'difficulty', 'type']),

            default => throw ApiException::notFound('Section not found'),
        };
    }

    /** @param array<string, mixed> $defaults */
    private static function taxonomy(string $kind, array $defaults, string $listed, string $label): Section
    {
        $sub = $kind === 'subcategory';

        return new Section(SiteTutorialTaxonomy::class,
            fixed: ['kind' => $kind],
            aliases: $sub ? ['category_id' => 'parent_id'] : [],
            references: $sub ? ['parent_id' => SiteTutorialTaxonomy::class] : [],
            defaults: static fn (): array => $defaults,
            filter: $sub ? static fn (Builder $q, Request $r) => self::whereChosen($q, $r, 'category_id', 'parent_id') : null,
            present: static function (array $row, SiteRecord $t) use ($sub): array {
                unset($row['parent_id']);
                if ($sub) {
                    $row['category_id'] = $t->getAttribute('parent_id');
                    $row['category_name'] = $t->getRelation('parent')?->getAttribute('name');
                }

                return $row;
            },
            hidden: ['kind'],
            messages: ['listed' => $listed, 'created' => "{$label} created successfully", 'updated' => "{$label} updated successfully",
                'deleted' => "{$label} deleted successfully", 'status' => "{$label} status updated successfully"],
            folder: 'website-builder/video-tutorials',
            with: $sub ? ['parent'] : []);
    }

    /**
     * v1 vendor builder: slide status "active" / "inactive" meant published / draft.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private static function vendorSlideStatus(array $v): array
    {
        if (isset($v['status']) && is_string($v['status'])) {
            $v['status'] = match (strtolower($v['status'])) {
                'active' => 'published',
                'inactive' => 'draft',
                default => $v['status'],
            };
        }

        return $v;
    }

    /** A slug made from another field when none was given (v1 toSlug). */
    private static function slugFrom(string $field): \Closure
    {
        return static function (array $v) use ($field): array {
            if ((! isset($v['slug']) || trim((string) $v['slug']) === '') && isset($v[$field]) && is_scalar($v[$field])) {
                $v['slug'] = Str::slug((string) $v[$field]);
            }

            return $v;
        };
    }

    /**
     * @param  Builder<SiteRecord>  $q
     * @param  list<string>  $columns
     */
    private static function search(Builder $q, Request $r, array $columns): void
    {
        $term = trim((string) $r->query('search', ''));
        if ($term !== '') {
            $q->where(static function (Builder $w) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $w->orWhere($column, 'like', "%{$term}%");
                }
            });
        }
    }

    /**
     * ?param=value narrows the list; "all" and blank do not (v1).
     *
     * @param  Builder<SiteRecord>  $q
     */
    private static function whereChosen(Builder $q, Request $r, string $param, string $column): void
    {
        $value = $r->query($param);
        if (is_scalar($value) && (string) $value !== '' && (string) $value !== 'all') {
            $q->where($column, (string) $value);
        }
    }

    /** @param Builder<SiteRecord> $q */
    private static function whereActive(Builder $q, Request $r): void
    {
        $value = $r->query('is_active');
        if (is_scalar($value) && (string) $value !== '' && (string) $value !== 'all') {
            $q->where('is_active', in_array((string) $value, ['true', '1'], true) ? 1 : 0);
        }
    }
}
