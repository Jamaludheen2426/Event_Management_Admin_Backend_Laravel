<?php

declare(strict_types=1);

use App\Core\Tenancy\Tenancy;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteBlock;
use App\Modules\Vendors\Models\Vendor;

/*
| The admin panel's Website Builder: /api/v1/website-builder/* edits the house
| vendor's marketing site (v1 companyWebsiteBuilder). Plus the admin FAQ
| screen (/faqs, /faq-categories) and colour palettes.
*/

beforeEach(function () {
    seedHouse();
    $this->admin = accessToken(adminUser());
});

it('lets anyone read the marketing site but only an editor change it', function () {
    $this->getJson('/api/v1/website-builder/hero-section?page=home')->assertOk()->assertJsonPath('data', null);
    $this->putJson('/api/v1/website-builder/hero-section', ['title' => 'Hi'])->assertUnauthorized();

    // The marketing site belongs to the house vendor and starts with the showcase categories.
    $site = Tenancy::bypass(fn () => Site::query()->where('kind', 'marketing')->sole());
    expect($site->vendor_id)->toBe(Vendor::houseId())->and($site->slug)->toBe('company-1')->and($site->status)->toBe('published');
    $this->getJson('/api/v1/website-builder/templates/categories')->assertOk()->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.name', 'Wedding')->assertJsonPath('data.0.slug', 'wedding');

    $viewer = adminUser(attributes: ['email' => 'viewer@ra.test'], role: null);
    giveRole($viewer, customRole(['website_builder.view' => false]));
    $viewer = accessToken($viewer);
    $this->flushHeaders();
    $this->withToken($viewer)->putJson('/api/v1/website-builder/hero-section', ['title' => 'Hi'])->assertForbidden();
    $this->withToken($viewer)->getJson('/api/v1/website-builder/contact-messages')->assertOk();
    $this->flushHeaders();
    $this->getJson('/api/v1/website-builder/contact-messages')->assertUnauthorized();

    $this->withToken($this->admin)->putJson('/api/v1/website-builder/hero-section?page=features', [
        'title' => 'We Create Moments', 'overlay_enabled' => false, 'design_json' => ['features' => ['title' => 'All features']], 'page_slug' => 'features',
    ])->assertOk()->assertJsonPath('message', 'Hero section saved')
        ->assertJsonPath('data.title', 'We Create Moments')->assertJsonPath('data.overlay_enabled', 0)
        ->assertJsonPath('data.overlay_color', '#000000')->assertJsonPath('data.design_json.features.title', 'All features')
        ->assertJsonMissingPath('data.page_slug');
    $this->flushHeaders();
    $this->getJson('/api/v1/website-builder/hero-section?page=features')->assertOk()->assertJsonPath('data.title', 'We Create Moments');
});

it('keeps the admin-only sections: features, steps, pricing, templates, tutorials', function () {
    $feature = $this->withToken($this->admin)->postJson('/api/v1/website-builder/features', ['title' => 'Guest list', 'image_url' => 'https://cdn.test/f.png', 'bullet_points_json' => '["One","Two"]'])
        ->assertCreated()->assertJsonPath('data.icon', 'calendar')->assertJsonPath('data.status', 'Active')->assertJsonPath('data.show_in_menu', 0)
        ->assertJsonPath('data.feature_image_url', 'https://cdn.test/f.png')->assertJsonPath('data.bullet_points_json', ['One', 'Two'])->json('data.id');
    $this->withToken($this->admin)->patchJson("/api/v1/website-builder/features/{$feature}/status", ['status' => 'Draft', 'is_active' => false])
        ->assertOk()->assertJsonPath('data', ['id' => $feature, 'is_active' => false, 'status' => 'Draft']);
    $this->withToken($this->admin)->deleteJson("/api/v1/website-builder/features/{$feature}")->assertOk()->assertJsonPath('data', null)
        ->assertJsonPath('message', 'Feature deleted');

    $this->withToken($this->admin)->postJson('/api/v1/website-builder/how-it-works', ['title' => 'Pick'])->assertCreated()->assertJsonPath('data.step_number', 1);
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/how-it-works', [])->assertCreated()
        ->assertJsonPath('data.step_number', 2)->assertJsonPath('data.title', 'New Step')->assertJsonPath('data.icon', 'gift');

    $plans = $this->withToken($this->admin)->putJson('/api/v1/website-builder/pricing/plans', ['items' => [
        ['plan_name' => 'Free', 'price_monthly' => '', 'is_popular' => '1', 'features_json' => [['label' => '1 event', 'included' => true]]],
        ['plan_name' => 'Pro', 'price_monthly' => 999],
    ]])->assertOk()->json('data');
    expect($plans[0])->toMatchArray(['plan_name' => 'Free', 'price_monthly' => '0.00', 'is_popular' => 1, 'currency' => '₹', 'period_label' => '/Month'])
        ->and($plans[1]['price_monthly'])->toBe('999.00');
    $again = $this->withToken($this->admin)->putJson('/api/v1/website-builder/pricing-plans', ['items' => [['id' => $plans[1]['id'], 'plan_name' => 'Pro+']]])->json('data');
    expect($again)->toHaveCount(1)->and($again[0]['id'])->toBe($plans[1]['id']);
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/pricing/settings', ['section_title' => 'Plans'])->assertOk()
        ->assertJsonPath('data.section_title', 'Plans')->assertJsonPath('data.badge_text', 'Simple, Transparent & Affordable Pricing');
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/pricing/matrix-features', ['title' => 'Guests', 'plan_availability' => ['free' => 'Up to 50']])
        ->assertCreated()->assertJsonPath('data.feature_name', 'Guests')->assertJsonPath('data.plan_values_json.free', 'Up to 50')->assertJsonPath('data.icon', 'Sparkles');

    $category = $this->withToken($this->admin)->getJson('/api/v1/website-builder/templates/categories')->json('data.1.id');
    $template = $this->withToken($this->admin)->postJson('/api/v1/website-builder/templates', ['template_name' => 'Floral Love', 'category_id' => $category, 'template_type' => 'engagement'])
        ->assertCreated()->assertJsonPath('data.slug', 'floral-love')->assertJsonPath('data.category_name', 'Engagement')->json('data.id');
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/templates', ['template_name' => 'Royal'])->assertCreated();
    $this->getJson('/api/v1/website-builder/templates?template_type=engagement')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/website-builder/templates/{$template}")->assertOk()->assertJsonPath('message', 'Template details retrieved');
    $this->getJson('/api/v1/website-builder/templates/99999')->assertNotFound()->assertJsonPath('message', 'Template not found');

    $cat = $this->withToken($this->admin)->postJson('/api/v1/website-builder/video-tutorial-categories', ['name' => 'Basics'])->assertCreated()
        ->assertJsonPath('data.icon', 'video')->json('data.id');
    $sub = $this->withToken($this->admin)->postJson('/api/v1/website-builder/video-tutorial-subcategories', ['name' => 'Setup', 'category_id' => $cat])
        ->assertCreated()->assertJsonPath('data.category_id', $cat)->assertJsonPath('data.category_name', 'Basics')->assertJsonMissingPath('data.kind')->json('data.id');
    $level = $this->withToken($this->admin)->postJson('/api/v1/website-builder/video-tutorial-difficulty-levels', [])->assertCreated()
        ->assertJsonPath('data.name', 'New Level')->json('data.id');
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/video-tutorials', [
        'title' => 'Start', 'category_id' => $cat, 'subcategory_id' => $sub, 'difficulty_level_id' => $level, 'tags' => 'a, b,,c', 'tutorial_type_id' => $sub,
    ])->assertCreated()->assertJsonPath('data.tags_json', ['a', 'b', 'c'])->assertJsonPath('data.category_name', 'Basics')
        ->assertJsonPath('data.subcategory_name', 'Setup')->assertJsonPath('data.difficulty_name', 'New Level');
    // Each list holds only its own kind.
    $this->getJson('/api/v1/website-builder/video-tutorial-categories')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/website-builder/video-tutorial-subcategories?category_id={$cat}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/website-builder/video-tutorials?search=Sta')->assertOk()->assertJsonCount(1, 'data');
});

it('keeps FAQs once, for the builder and the admin FAQ screen', function () {
    $category = $this->withToken($this->admin)->postJson('/api/v1/website-builder/faq-categories', ['name' => 'Billing'])->assertCreated()
        ->assertJsonPath('data.color', '#7C3AED')->json('data.id');
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/faqs', ['faq_category_id' => $category, 'question' => 'Refunds?', 'answer' => 'Yes', 'tags' => 'money'])
        ->assertCreated()->assertJsonPath('message', 'FAQ created successfully');
    $this->getJson('/api/v1/website-builder/faqs?search=money')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category_name', 'Billing')->assertJsonPath('data.0.category.name', 'Billing');

    // The admin FAQ screen reads the same rows, in its own shapes.
    $this->withToken($this->admin)->getJson('/api/v1/faqs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.totalItems', 1)
        ->assertJsonPath('data.0.category.name', 'Billing')->assertJsonMissingPath('data.0.site_id');
    $id = $this->withToken($this->admin)->postJson('/api/v1/faqs', ['faq_category_id' => $category, 'question' => 'Discounts?', 'answer' => 'Sometimes', 'sort_order' => 3])
        ->assertCreated()->assertJsonPath('data.faq.question', 'Discounts?')->json('data.faq.id');
    $this->withToken($this->admin)->postJson('/api/v1/faqs', ['faq_category_id' => $category, 'question' => 'Q', 'answer' => 'A', 'sort_order' => 3])
        ->assertStatus(400)->assertJsonPath('message', 'Sort order 3 is already taken in this category.');
    $this->withToken($this->admin)->postJson('/api/v1/faqs', ['question' => 'Q', 'answer' => 'A'])->assertStatus(400)->assertJsonPath('message', 'FAQ category is required');
    $this->withToken($this->admin)->getJson("/api/v1/faqs/{$id}")->assertOk()->assertJsonPath('data.faq.category.id', $category);
    $this->withToken($this->admin)->postJson('/api/v1/faq-categories', ['name' => 'Billing'])
        ->assertStatus(400)->assertJsonPath('message', 'A category with the name "Billing" already exists.');
    $this->getJson('/api/v1/website-builder/faqs')->assertOk()->assertJsonCount(2, 'data');
});

it('keeps blocks per page and highlight cards', function () {
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/ui-blocks', ['page_slug' => 'home', 'items' => [
        ['id' => 'hero', 'label' => 'Hero', 'visible' => true, 'sort_order' => 1],
        ['id' => 'faqs', 'visible' => false, 'sort_order' => 2],
    ]])->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/ui-blocks', ['page_slug' => 'pricing', 'items' => [['id' => 'plans', 'visible' => true]]])->assertOk();

    // v1 wrote the admin's blocks over vendor 1's own vendor site (bug 8); each page now keeps its own.
    $this->getJson('/api/v1/website-builder/ui-blocks?page_slug=home')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.block_key', 'hero')->assertJsonPath('data.1.is_visible', 0);
    $this->getJson('/api/v1/website-builder/ui-blocks?page_slug=pricing')->assertOk()->assertJsonCount(1, 'data');
    expect(SiteBlock::query()->count())->toBe(3);

    $this->getJson('/api/v1/website-builder/highlights?page_slug=home&instance=1')->assertOk()->assertJsonPath('data', null);
    $saved = $this->withToken($this->admin)->putJson('/api/v1/website-builder/highlights', ['page_slug' => 'home', 'instance' => 1, 'items' => [['id' => 7, 'title' => 'Fast']]])
        ->assertOk()->json('data');
    expect($saved['id'])->toBeInt()->and($saved['items'][0]['title'])->toBe('Fast');
    $this->getJson('/api/v1/website-builder/highlights?page_slug=home&instance=1')->assertOk()
        ->assertJsonPath('data.id', $saved['id'])->assertJsonPath('data.items.0.title', 'Fast')->assertJsonPath('data.instance', 1);
});

it('keeps colour palettes, which v1 could not edit or delete', function () {
    $id = $this->withToken($this->admin)->postJson('/api/v1/color-palettes', ['name' => 'Sunset', 'primary_bg_color' => '#FF0000'])
        ->assertCreated()->assertJsonPath('message', 'Color palette created successfully')->assertJsonPath('data.palette.name', 'Sunset')->json('data.palette.id');
    $this->withToken($this->admin)->postJson('/api/v1/color-palettes', ['primary_bg_color' => '#FF0000'])->assertStatus(400)->assertJsonPath('message', 'Name is required');
    $this->withToken($this->admin)->putJson("/api/v1/color-palettes/{$id}", ['name' => 'Dusk'])->assertOk()->assertJsonPath('data.palette.name', 'Dusk');
    $this->withToken($this->admin)->getJson('/api/v1/color-palettes?search=Dus')->assertOk()->assertJsonCount(1, 'data');

    // The vendor builder offers the active palettes.
    $this->withToken(accessToken(vendorOwner()))->getJson('/api/v1/vendors/website/builder-data')->assertOk()
        ->assertJsonPath('data.colorPalettes.0.name', 'Dusk');
    $this->withToken($this->admin)->deleteJson("/api/v1/color-palettes/{$id}")->assertOk();
    $this->withToken($this->admin)->getJson("/api/v1/color-palettes/{$id}")->assertNotFound();
});
