<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sites, part 1: one website builder for everyone.
|
| v1 had two: company_websites + 35 company_website_* tables (the marketing
| site, edited from the admin panel) and vendor_websites + 21
| vendor_website_* tables (a vendor's own site). Both become `sites` with a
| `kind`: the house vendor (vendor 1) owns the marketing site; every vendor,
| the house vendor included, may own one vendor site.
|
| Every content table hangs off its site (site_id) and keeps v1's column names,
| because the builder screens and the public site read rows as they are.
| v1's singleton tables (basic information, hero, footer, SEO, login page,
| theme, contact settings, pricing headings) are one row each in
| site_settings, holding that section's fields as a JSON document.
|
| Dropped: vendor_website_publish_snapshots (never read), company_templates.use_count,
| the vendor tables' created_by/updated_by/deleted_by (nothing read them).
*/
return new class extends Migration
{
    public function up(): void
    {
        // Admin-made colour sets a site can start from (v1 color_palettes).
        Schema::create('color_palettes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('primary_bg_color', 50)->nullable();
            $table->string('primary_text_color', 50)->nullable();
            $table->string('secondary_text_color', 50)->nullable();
            $table->string('paragraph_color', 50)->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['marketing', 'vendor']);
            $table->string('slug', 160);
            $table->string('custom_domain')->nullable();
            $table->enum('status', ['draft', 'published', 'maintenance'])->default('draft');
            $table->string('theme_id', 100)->nullable();
            $table->foreignId('palette_id')->nullable()->constrained('color_palettes')->nullOnDelete();
            $table->unsignedInteger('publish_version')->default(0);
            $table->timestamp('published_at')->nullable();
            // Colours, layout choices: the builder's own document.
            $table->json('settings_json')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->aliveColumn();
            $table->unique(['slug', 'alive']);
            $table->unique(['custom_domain', 'alive']);
            $table->unique(['vendor_id', 'kind', 'alive']);
        });

        // One row per (site, section): basic-information, hero, footer, seo, login, theme, contact, pricing.
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('section', 40);
            $table->json('data')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->unique(['site_id', 'section']);
        });

        Schema::create('site_social_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('icon_key', 120)->nullable();
            $table->string('icon_color', 50)->nullable();
            $table->string('label', 100)->nullable();
            $table->text('url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('page_type', 20)->default('custom');
            $table->string('title');
            $table->string('slug', 180);
            $table->longText('content')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->text('og_image_url')->nullable();
            $table->string('status', 20)->default('published');
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_system')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->unique(['site_id', 'slug']);
        });

        Schema::create('site_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('site_menu_items')->nullOnDelete();
            $table->string('label', 150);
            $table->string('item_type', 20)->default('custom');
            $table->foreignId('page_id')->nullable()->constrained('site_pages')->nullOnDelete();
            $table->text('url')->nullable();
            $table->string('target', 20)->default('_self');
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_visible')->default(1);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        // Which blocks a page shows, in which order. page_slug '' = the site-wide list.
        Schema::create('site_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('page_slug', 100)->default('');
            $table->string('block_key', 100);
            $table->string('label', 150)->nullable();
            $table->string('variant_key', 100)->nullable();
            $table->unsignedTinyInteger('is_visible')->default(1);
            $table->integer('sort_order')->default(0);
            $table->json('config_json')->nullable();
            $table->json('design_json')->nullable();
            $table->json('mobile_json')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
            $table->unique(['site_id', 'page_slug', 'block_key']);
        });

        Schema::create('site_sliders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('slider_type', 20)->default('simple');
            $table->string('title')->nullable();
            $table->string('slider_height', 50)->default('medium');
            $table->unsignedTinyInteger('autoplay')->default(1);
            $table->integer('autoplay_speed')->default(5000);
            $table->string('status', 20)->default('active');
            $table->json('config_json')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_slides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slider_id')->constrained('site_sliders')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->string('button_label', 100)->nullable();
            // A page id or a page slug (the builders send either).
            $table->string('button_page_id', 80)->nullable();
            $table->text('button_url')->nullable();
            $table->string('button_color', 50)->nullable();
            $table->string('button_text_color', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_gallery_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_gallery_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('site_gallery_categories')->nullOnDelete();
            $table->string('event_name')->nullable();
            $table->string('event_type', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->text('image_url')->nullable();
            $table->string('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_contact_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('site_contact_categories')->nullOnDelete();
            $table->string('category_other', 190)->nullable();
            $table->string('name', 160)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 40)->default('new');
            $table->json('metadata_json')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        // Which social links the contact page shows, in which order.
        Schema::create('site_contact_social_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_link_id')->constrained('site_social_links')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_visible')->default(1);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_testimonials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name', 160);
            $table->text('photo_url')->nullable();
            $table->string('event_name')->nullable();
            $table->text('feedback')->nullable();
            $table->decimal('rating', 3, 2)->default(5);
            $table->unsignedTinyInteger('show_rating')->default(1);
            $table->unsignedTinyInteger('is_featured')->default(0);
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        // v1 *_clients + *_sponsors: logo strips, told apart by type.
        Schema::create('site_logos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['client', 'sponsor']);
            $table->string('name', 160)->nullable();
            $table->text('logo_url')->nullable();
            $table->text('website_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('short_description')->nullable();
            $table->longText('detailed_description')->nullable();
            // A Lucide name or inline SVG.
            $table->longText('icon')->nullable();
            $table->longText('custom_icon_url')->nullable();
            $table->longText('feature_image_url')->nullable();
            $table->json('bullet_points_json')->nullable();
            $table->unsignedTinyInteger('show_in_menu')->default(1);
            $table->integer('menu_order')->default(1);
            $table->string('status', 20)->default('Active');
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        // "How it works" steps.
        Schema::create('site_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->integer('step_number')->default(1);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('highlight_title')->nullable();
            $table->string('highlight_subtext')->nullable();
            $table->longText('icon')->nullable();
            $table->longText('illustration_url')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('site_pricing_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('plan_name');
            $table->string('subtitle')->default('');
            $table->string('target_type', 20)->default('individuals');
            $table->string('currency', 50)->default('₹');
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->default(0);
            $table->string('period_label', 100)->default('/Month');
            $table->string('badge_text')->default('');
            $table->string('badge_style', 50)->default('filled');
            $table->unsignedTinyInteger('is_popular')->default(0);
            $table->json('features_json')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // The plan comparison table's rows.
        Schema::create('site_pricing_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('feature_name');
            $table->string('icon')->default('Sparkles');
            $table->text('description')->nullable();
            $table->string('category')->default('General');
            $table->json('plan_values_json')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        // FAQs: the builder's FAQ section and the admin FAQ screen (v1 had a table pair each).
        Schema::create('site_faq_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 100)->default('HelpCircle');
            $table->string('color', 50)->default('#7C3AED');
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('site_faqs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faq_category_id')->nullable()->constrained('site_faq_categories')->nullOnDelete();
            $table->text('question');
            $table->longText('answer');
            $table->string('tags')->nullable();
            $table->unsignedTinyInteger('is_featured')->default(0);
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->enum('approval_status', ['approved', 'pending'])->default('approved');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // A page's highlight cards (one row per page and position on it).
        Schema::create('site_highlights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('page_slug', 100);
            $table->integer('instance')->default(1);
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'page_slug', 'instance']);
        });

        // The template showcase (v1 company_template_categories + company_templates).
        Schema::create('site_template_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('icon', 50)->default('tag');
            $table->string('color', 20)->default('#6A38F5');
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('site_template_categories')->nullOnDelete();
            $table->string('template_name', 200);
            $table->string('slug', 200)->nullable();
            $table->longText('description')->nullable();
            $table->string('template_type', 30)->default('wedding');
            $table->string('design_style', 30)->default('classic');
            $table->string('primary_color', 20)->default('#6A38F5');
            $table->longText('thumbnail_url')->nullable();
            $table->longText('template_file_url')->nullable();
            $table->longText('preview_url')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->unsignedTinyInteger('allow_customize')->default(1);
            $table->unsignedTinyInteger('is_draft')->default(0);
            $table->unsignedTinyInteger('is_popular')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Video tutorials and their four lists (v1: four tables), told apart by kind.
        Schema::create('site_tutorial_taxonomies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['category', 'subcategory', 'difficulty', 'type']);
            // A subcategory's category.
            $table->foreignId('parent_id')->nullable()->constrained('site_tutorial_taxonomies')->nullOnDelete();
            $table->string('name', 50);
            $table->string('description', 150)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('site_tutorials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->string('short_description', 200)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('site_tutorial_taxonomies')->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained('site_tutorial_taxonomies')->nullOnDelete();
            $table->json('tags_json')->nullable();
            $table->string('video_source', 20)->default('upload');
            $table->string('video_url', 500)->nullable();
            $table->longText('video_file_url')->nullable();
            $table->integer('duration_seconds')->default(0);
            $table->foreignId('difficulty_level_id')->nullable()->constrained('site_tutorial_taxonomies')->nullOnDelete();
            $table->foreignId('tutorial_type_id')->nullable()->constrained('site_tutorial_taxonomies')->nullOnDelete();
            $table->string('key_takeaways', 300)->nullable();
            $table->longText('thumbnail_url')->nullable();
            $table->unsignedTinyInteger('is_featured')->default(0);
            $table->date('publish_date')->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'site_tutorials', 'site_tutorial_taxonomies', 'site_templates', 'site_template_categories', 'site_highlights',
            'site_faqs', 'site_faq_categories', 'site_pricing_features', 'site_pricing_plans', 'site_steps', 'site_features',
            'site_logos', 'site_testimonials', 'site_contact_social_links', 'site_contact_messages', 'site_contact_categories',
            'site_gallery_items', 'site_gallery_categories', 'site_slides', 'site_sliders', 'site_blocks', 'site_menu_items',
            'site_pages', 'site_social_links', 'site_settings', 'sites', 'color_palettes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
