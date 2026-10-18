<?php

declare(strict_types=1);

use App\Core\Tenancy\Tenancy;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SitePage;

/*
| The builder app: /api/v1/vendors/website/* (v1 vendorWebsiteBuilder), and a
| vendor's public website (/api/v1/public/vendors/{slug}/...).
*/

beforeEach(function () {
    seedHouse();
    $this->owner = vendorOwner(['company_name' => 'Ash Events']);
    $this->token = accessToken($this->owner);
});

it('answers empty until the first save sets the site up with its fixed pages', function () {
    $this->withToken($this->token)->getJson('/api/v1/vendors/website/builder-data')->assertOk()
        ->assertJsonPath('data.website', null)->assertJsonPath('data.pages', [])->assertJsonPath('data.schemaReady', true);
    $this->withToken($this->token)->getJson('/api/v1/vendors/website/footer')->assertOk()->assertJsonPath('data', null);

    $this->withToken($this->token)->putJson('/api/v1/vendors/website/basic-information', [
        'companyName' => 'Ash Events', 'logoUrl' => 'https://cdn.test/logo.png', 'showSignIn' => false, 'contactType' => 'weird',
    ])->assertOk()->assertJsonPath('message', 'Basic information saved')
        ->assertJsonPath('data.company_name', 'Ash Events')->assertJsonPath('data.logo_url', 'https://cdn.test/logo.png')
        ->assertJsonPath('data.show_signin', 0)->assertJsonPath('data.contact_type', 'default')
        ->assertJsonPath('data.header_color', '#FFFFFF');

    $site = Tenancy::bypass(fn () => Site::query()->where('vendor_id', $this->owner->vendor_id)->sole());
    expect($site->kind)->toBe('vendor')->and($site->slug)->toBe("ash-events-{$this->owner->vendor_id}")->and($site->status)->toBe('draft')
        ->and(SitePage::query()->where('site_id', $site->id)->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(['about-us', 'service', 'events', 'terms-conditions', 'privacy-policy', 'maintenance']);

    $this->withToken($this->token)->getJson('/api/v1/vendors/website')->assertOk()->assertJsonPath('data.slug', $site->slug);
    $this->withToken($this->token)->putJson('/api/v1/vendors/website', ['settings_json' => ['colors' => ['primary' => '#111']], 'status' => 'nonsense'])
        ->assertOk()->assertJsonPath('data.settings_json.colors.primary', '#111')->assertJsonPath('data.status', 'draft');
    $this->withToken($this->token)->postJson('/api/v1/vendors/website/publish')->assertOk()
        ->assertJsonPath('data.website.status', 'published')->assertJsonPath('data.website.publish_version', 1)
        ->assertJsonPath('data.payload.basicInformation.company_name', 'Ash Events');
});

it('keeps list sections per site, with ids that survive a bulk save', function () {
    $this->withToken($this->token)->postJson('/api/v1/vendors/website/testimonials', ['customerName' => 'Asha', 'feedback' => 'Lovely'])
        ->assertCreated()->assertJsonPath('message', 'Testimonial created')->assertJsonPath('data.customer_name', 'Asha')
        ->assertJsonPath('data.rating', '5.00')->assertJsonPath('data.show_rating', 1);
    $first = $this->withToken($this->token)->getJson('/api/v1/vendors/website/testimonials')->assertOk()->json('data.0.id');

    $saved = $this->withToken($this->token)->putJson('/api/v1/vendors/website/testimonials', ['items' => [
        ['id' => $first, 'customer_name' => 'Asha K', 'feedback' => 'Lovely'],
        ['customer_name' => 'Ravi', 'feedback' => 'Great', 'is_active' => false],
    ]])->assertOk()->assertJsonPath('message', 'Testimonials saved')->json('data');
    expect($saved)->toHaveCount(2)->and($saved[0]['id'])->toBe($first)->and($saved[0]['customer_name'])->toBe('Asha K')
        ->and($saved[1]['is_active'])->toBe(0);

    // Another vendor sees nothing of this site and cannot touch its rows.
    $other = accessToken(vendorOwner(['company_name' => 'Other Co']));
    $this->withToken($other)->getJson('/api/v1/vendors/website/testimonials')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($other)->putJson("/api/v1/vendors/website/testimonials/{$first}", ['customer_name' => 'X'])->assertNotFound();
    $this->withToken($other)->deleteJson("/api/v1/vendors/website/testimonials/{$first}")->assertNotFound();

    $this->withToken($this->token)->deleteJson("/api/v1/vendors/website/testimonials/{$first}")->assertOk()
        ->assertJsonPath('data', ['id' => $first, 'deleted' => true]);
});

it('coerces the vendor builder\'s ENUM values and links only to its own rows', function () {
    $page = $this->withToken($this->token)->postJson('/api/v1/vendors/website/pages', ['title' => 'Offers', 'slug' => 'offers', 'page_type' => 'weird'])
        ->assertCreated()->assertJsonPath('data.page_type', 'custom')->assertJsonPath('data.status', 'draft')->json('data.id');
    $this->withToken($this->token)->postJson('/api/v1/vendors/website/pages', ['title' => 'Offers again', 'slug' => 'offers'])
        ->assertStatus(400)->assertJsonPath('message', 'A page with this slug already exists.');

    $menu = $this->withToken($this->token)->putJson('/api/v1/vendors/website/menu-items', ['items' => [
        ['client_id' => 'a', 'label' => 'Home', 'item_type' => 'home', 'target' => '_self'],
        ['client_id' => 'b', 'parent_client_id' => 'a', 'label' => 'Offers', 'item_type' => 'page', 'page_id' => $page, 'target' => '_blank'],
        ['label' => 'Somewhere', 'item_type' => 'static', 'page_id' => 'about-us'],
    ]])->assertOk()->json('data');
    expect($menu[0])->toMatchArray(['label' => 'Home', 'target' => 'self', 'parent_id' => null])
        ->and($menu[1])->toMatchArray(['label' => 'Offers', 'target' => 'blank', 'parent_id' => $menu[0]['id'], 'page_id' => $page])
        ->and($menu[2])->toMatchArray(['item_type' => 'page', 'page_id' => null]);

    $slider = $this->withToken($this->token)->postJson('/api/v1/vendors/website/sliders', ['title' => 'Top', 'slider_type' => 'advanced'])
        ->assertCreated()->json('data.id');
    $slides = $this->withToken($this->token)->putJson("/api/v1/vendors/website/sliders/{$slider}/items", [
        ['title' => 'One', 'status' => 'active', 'buttonPage' => 'about-us'],
        ['title' => 'Two', 'status' => 'inactive'],
    ])->assertOk()->json('data');
    expect($slides[0])->toMatchArray(['status' => 'published', 'button_page_id' => 'about-us', 'slider_id' => $slider])
        ->and($slides[1]['status'])->toBe('draft');
    $this->withToken($this->token)->getJson('/api/v1/vendors/website/sliders/999999/items')->assertNotFound();

    // The contact page's social links must be this site's links.
    $links = $this->withToken($this->token)->putJson('/api/v1/vendors/website/social-links', [['label' => 'Instagram', 'icon' => 'mdi:instagram', 'url' => 'https://ig.test']])
        ->assertOk()->json('data');
    expect($links[0]['icon_key'])->toBe('mdi:instagram');
    $this->withToken($this->token)->putJson('/api/v1/vendors/website/contact/settings', ['mode' => 'dynamic', 'contactFormEnabled' => false])
        ->assertOk()->assertJsonPath('data.mode', 'dynamic')->assertJsonPath('data.contact_form_enabled', 0);
    $contactLinks = $this->withToken($this->token)->putJson('/api/v1/vendors/website/contact/social-links', ['items' => [
        ['social_link_id' => $links[0]['id'], 'is_visible' => true], ['social_link_id' => 999999],
    ]])->assertOk()->json('data');
    expect($contactLinks)->toHaveCount(1)->and($contactLinks[0]['social_link_id'])->toBe($links[0]['id'])
        ->and($contactLinks[0]['contact_setting_id'])->toBeInt();

    // "Other" stays: the form's fallback subject.
    $otherCategory = $this->withToken($this->token)->postJson('/api/v1/vendors/website/contact/categories', ['name' => 'Other'])
        ->assertCreated()->assertJsonPath('data.slug', 'other')->json('data.id');
    $this->withToken($this->token)->deleteJson("/api/v1/vendors/website/contact/categories/{$otherCategory}")
        ->assertStatus(400)->assertJsonPath('message', 'The "Other" category cannot be deleted.');
});

it('shows a preview of only what visitors would see', function () {
    $this->withToken($this->token)->putJson('/api/v1/vendors/website/ui-blocks', ['items' => [
        ['block_key' => 'testimonials', 'is_visible' => false],
        ['block_key' => 'basic-clients', 'is_visible' => true],
        ['block_key' => 'hero-section', 'is_visible' => true],
    ]])->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.block_key', 'testimonials');
    $this->withToken($this->token)->postJson('/api/v1/vendors/website/testimonials', ['customer_name' => 'Asha'])->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/vendors/website/portfolio/clients', ['name' => 'HDFC', 'logo_url' => 'https://cdn.test/h.png'])->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/vendors/website/portfolio/clients', ['name' => 'Off', 'is_active' => 0])->assertCreated();

    $this->withToken($this->token)->getJson('/api/v1/vendors/website/preview-payload')->assertOk()
        ->assertJsonCount(0, 'data.testimonials')->assertJsonCount(1, 'data.clients')->assertJsonPath('data.clients.0.name', 'HDFC')
        ->assertJsonCount(2, 'data.uiBlocks');
    $this->withToken($this->token)->getJson('/api/v1/vendors/website/builder-data')->assertOk()
        ->assertJsonCount(1, 'data.testimonials')->assertJsonCount(2, 'data.clients');
});

it('serves the vendor\'s public website and takes contact messages', function () {
    $this->withToken($this->token)->putJson('/api/v1/vendors/website/seo', ['meta_title' => 'Ash', 'robots_meta' => 'index-nofollow'])
        ->assertOk()->assertJsonPath('data.default_title', 'Ash')->assertJsonPath('data.robots_index', 1)->assertJsonPath('data.robots_follow', 0);
    $site = Tenancy::bypass(fn () => Site::query()->where('vendor_id', $this->owner->vendor_id)->sole());

    // By the site's address or by the company name.
    foreach ([$site->slug, 'ash-events'] as $slug) {
        $this->getJson("/api/v1/public/vendors/{$slug}/website-data")->assertOk()
            ->assertJsonPath('data.vendor.company_name', 'Ash Events')->assertJsonPath('data.seo.default_title', 'Ash')
            ->assertJsonPath('data.terms_content', '<h2>Terms &amp; Conditions</h2><p>Add the terms and conditions visitors agree to when signing up or using your website.</p>')
            ->assertJsonMissingPath('data.vendor.bank_name');
    }
    $this->getJson('/api/v1/public/website/nobody')->assertNotFound()->assertJsonPath('message', 'Vendor not found');

    $this->postJson("/api/v1/public/vendors/{$site->slug}/contact", ['name' => 'Ravi'])
        ->assertStatus(400)->assertJsonPath('message', 'Name, email, and message are required');
    $this->postJson("/api/v1/public/vendors/{$site->slug}/contact", ['name' => 'Ravi', 'email' => 'r@ra.test', 'message' => 'Hi', 'categoryId' => 999])
        ->assertCreated()->assertJsonPath('message', 'Contact message submitted successfully')
        ->assertJsonPath('data.status', 'new')->assertJsonPath('data.category_id', null);
    $this->withToken($this->token)->getJson('/api/v1/vendors/website/contact/messages')->assertOk()->assertJsonCount(1, 'data');

    // The public sign-up form works on the site's address too.
    $this->postJson("/api/v1/public/vendors/{$site->slug}/newsletter-subscribe", ['email' => 'fan@ra.test'])->assertCreated();
});
