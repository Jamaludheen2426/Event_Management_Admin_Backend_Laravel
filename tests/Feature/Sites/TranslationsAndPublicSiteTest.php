<?php

declare(strict_types=1);

use App\Core\Contracts\MachineTranslator;
use App\Core\Tenancy\Tenancy;
use App\Modules\Clients\Actions\SocialSignIn;
use App\Modules\Sites\Actions\SiteTranslations;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteTranslation;
use App\Modules\Sites\Models\SiteTranslationKey;
use Tests\Support\FakeTranslator;

/*
| The marketing site's languages and content translations
| (/api/v1/website-builder/translations/*, content-translations,
| translation-keys) and the public site app's read model
| (/api/v1/public/site/resolve, /site/bundle).
*/

beforeEach(function () {
    seedHouse();
    $this->admin = accessToken(adminUser());
    app()->instance(MachineTranslator::class, $this->translator = new FakeTranslator);
});

function languages(string $token): array
{
    $en = test()->withToken($token)->postJson('/api/v1/website-builder/translations/languages', ['code' => 'en', 'name' => 'English'])
        ->assertCreated()->json('data.id');
    test()->withToken($token)->patchJson("/api/v1/website-builder/translations/languages/{$en}/default")->assertOk()->assertJsonPath('data.is_default', 1);
    $ta = test()->withToken($token)->postJson('/api/v1/website-builder/translations/languages', ['code' => 'TA', 'name' => 'Tamil', 'native_name' => 'தமிழ்'])
        ->assertCreated()->assertJsonPath('data.code', 'ta')->json('data.id');

    return [$en, $ta];
}

it('keeps the site\'s languages, with one default that stays on', function () {
    [$en, $ta] = languages($this->admin);
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/translations/languages', ['code' => 'ta', 'name' => 'Tamil'])
        ->assertStatus(400)->assertJsonPath('message', 'A language with this code already exists');
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/translations/languages', ['code' => 'hi'])
        ->assertStatus(400)->assertJsonPath('message', 'code and name are required');
    $this->withToken($this->admin)->putJson("/api/v1/website-builder/translations/languages/{$en}", ['is_active' => false])
        ->assertStatus(400)->assertJsonPath('message', 'The default language cannot be deactivated');
    $this->withToken($this->admin)->deleteJson("/api/v1/website-builder/translations/languages/{$en}")
        ->assertStatus(400)->assertJsonPath('message', 'The default language cannot be deleted');

    $this->flushHeaders();
    $this->getJson('/api/v1/website-builder/translations/public-languages')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.code', 'en')->assertJsonPath('data.0.is_default', true)->assertJsonPath('data.1.native_name', 'தமிழ்');
    $this->withToken($this->admin)->putJson("/api/v1/website-builder/translations/languages/{$ta}", ['is_active' => false])->assertOk();
    $this->flushHeaders();
    $this->getJson('/api/v1/website-builder/translations/public-languages')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/website-builder/translations/bundle?code=ta')->assertOk()
        ->assertJsonPath('message', 'Language not available')->assertJsonPath('data.language', null);
    $this->getJson('/api/v1/website-builder/translations/bundle')->assertStatus(400);
});

it('finds translatable text in the content, translates only empty slots and keeps brand names out', function () {
    [, $ta] = languages($this->admin);
    $faq = $this->withToken($this->admin)->postJson('/api/v1/website-builder/faqs', ['question' => 'Refunds?', 'answer' => 'Yes'])->json('data.id');
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/faqs', ['question' => 'Discounts?', 'answer' => 'No'])->assertCreated();
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/clients', ['name' => 'HDFC Bank', 'logo_url' => 'https://cdn.test/h.png'])->assertCreated();
    $plan = $this->withToken($this->admin)->postJson('/api/v1/website-builder/pricing/plans', ['plan_name' => 'Pro', 'features_json' => [['label' => '1 Active Event', 'included' => true], '', 'Gallery']])
        ->json('data.id');
    $hero = $this->withToken($this->admin)->putJson('/api/v1/website-builder/hero-section', [
        'title' => 'Moments', 'button_1_json' => ['label' => 'Start'],
        'design_json' => ['home' => ['title' => 'Moments'], 'pricing' => ['title' => 'Plans', 'badge_text' => 'Simple']],
    ])->json('data.id');
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/basic-information', ['company_name' => 'RA Events', 'address' => '1 Main St'])->assertOk();

    $keys = collect($this->withToken($this->admin)->getJson('/api/v1/website-builder/translation-keys')->assertOk()->json('data'));
    expect($keys->where('section', 'faqs')->where('record_id', $faq)->pluck('field_label')->all())->toBe(['Question — Refunds?', 'Answer — Refunds?'])
        ->and($keys->where('section', 'pricing-plans')->where('record_id', $plan)->pluck('field_key')->all())
        ->toBe(['plan_name', 'period_label', 'feature_1', 'feature_3'])
        ->and($keys->where('section', 'hero-section')->pluck('page_slug')->unique()->values()->all())->toBe(['home', 'pricing'])
        ->and($keys->where('section', 'hero-section')->where('page_slug', 'pricing')->firstWhere('field_key', 'button_1_label')['default_value'])->toBe('Start')
        ->and($keys->where('section', 'clients'))->toBeEmpty()
        ->and($keys->where('section', 'basic-information')->pluck('field_key')->all())->toBe(['address'])
        ->and($keys->where('section', 'ui-chrome')->count())->toBe(229);

    // A hand-made translation is kept; only empty slots are filled, in every language.
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/content-translations', [
        'section' => 'faqs', 'record_id' => $faq, 'language_id' => $ta, 'values' => ['question' => "திரும்பப் பெறலாமா?\n\n\n"],
    ])->assertOk()->assertJsonPath("data.{$ta}.question", 'திரும்பப் பெறலாமா?');
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/content-translations/auto-translate', ['section' => 'faqs', 'record_id' => $faq])
        ->assertOk()->assertJsonPath("data.{$ta}.question", 'திரும்பப் பெறலாமா?')->assertJsonPath("data.{$ta}.answer", '[ta] Yes');
    expect($this->translator->calls)->toBe(1);

    $stats = $this->withToken($this->admin)->getJson('/api/v1/website-builder/translation-keys/stats')->assertOk()->json('data');
    expect($stats['languages'][0])->toMatchArray(['id' => $ta, 'total' => 2, 'reviewed' => 1, 'auto' => 1]);
    expect($this->withToken($this->admin)->getJson('/api/v1/website-builder/translation-keys/sections')->json('data'))->toContain('faqs', 'ui-chrome');

    // The rendered site's overlay.
    $this->flushHeaders();
    $this->getJson('/api/v1/website-builder/translations/bundle?lang=ta')->assertOk()
        ->assertJsonPath("data.translations.faqs||{$faq}.answer", '[ta] Yes')->assertJsonPath('data.language.code', 'ta');
    $this->getJson("/api/v1/website-builder/content-translations?section=faqs&record_id={$faq}")->assertOk()
        ->assertJsonPath("data.{$ta}.answer", '[ta] Yes');

    // Translate everything else into Tamil.
    $done = $this->withToken($this->admin)->postJson("/api/v1/website-builder/translations/languages/{$ta}/translate-all")->assertOk()->json('data');
    expect($done['quotaExceeded'])->toBeFalse()->and($done['skipped'])->toBe(2)->and($done['created'])->toBeGreaterThan(229);
    expect(SiteTranslation::query()->where('section', 'hero-section')->where('page_slug', 'pricing')->where('field_key', 'title')->value('value'))->toBe('[ta] Plans');

    // Deleting the content drops its keys at the next scan, never the translations.
    $this->withToken($this->admin)->deleteJson("/api/v1/website-builder/faqs/{$faq}")->assertOk();
    $this->withToken($this->admin)->getJson('/api/v1/website-builder/translation-keys?section=faqs')->assertOk();
    app(SiteTranslations::class)->sync(Tenancy::bypass(fn () => Site::query()->where('kind', 'marketing')->sole()));
    expect(SiteTranslationKey::query()->where('section', 'faqs')->where('record_id', $faq)->exists())->toBeFalse()
        ->and(SiteTranslation::query()->where('section', 'faqs')->where('record_id', $faq)->count())->toBe(2);
});

it('edits one key across languages and streams auto-translate progress', function () {
    [, $ta] = languages($this->admin);
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/seo', ['default_title' => 'Event Invit', 'site_name' => 'RA'])->assertOk();
    $keys = $this->withToken($this->admin)->putJson('/api/v1/website-builder/translation-keys/register', [
        'section' => 'custom', 'fields' => [['key' => 'cta', 'label' => 'CTA', 'value' => 'Book now'], ['key' => 'blank', 'label' => 'Blank', 'value' => '']],
    ])->assertOk()->json('data');
    expect($keys)->toHaveCount(1);
    $key = $keys[0]['id'];

    $this->withToken($this->admin)->putJson("/api/v1/website-builder/translation-keys/{$key}/translations", ['translations' => [['language_id' => $ta, 'value' => 'இப்போது']]])
        ->assertOk()->assertJsonPath('data.translations.0.value', 'இப்போது')->assertJsonPath('data.translations.0.status', 'reviewed');
    $this->withToken($this->admin)->postJson("/api/v1/website-builder/translation-keys/{$key}/retranslate")->assertOk()
        ->assertJsonPath('data.retranslated', true);
    expect(SiteTranslation::query()->where('field_key', 'cta')->value('value'))->toBe('[ta] Book now');

    $stream = $this->withToken($this->admin)->get('/api/v1/website-builder/content-translations/auto-translate/stream?section=seo')
        ->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')->streamedContent();
    expect($stream)->toContain('event: progress')->toContain('"phase":"start"')->toContain('event: complete')->toContain('[ta] Event Invit');

    $this->withToken($this->admin)->deleteJson("/api/v1/website-builder/translation-keys/{$key}")->assertOk()->assertJsonPath('data.deleted', true);
    expect(SiteTranslation::query()->where('field_key', 'cta')->exists())->toBeFalse();
});

it('serves any site to the public site app by the host a visitor typed', function () {
    config(['clients.public_site_root_domains' => ['eventinvit.app']]);
    [, $ta] = languages($this->admin);
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/footer', ['copyright_text' => '© RA'])->assertOk();
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/hero-section', ['title' => 'Base', 'design_json' => ['pricing' => ['title' => 'Plans']]])->assertOk();
    $this->withToken($this->admin)->postJson('/api/v1/website-builder/faqs', ['question' => 'Off?', 'answer' => 'x', 'is_active' => false])->assertCreated();
    $faq = $this->withToken($this->admin)->postJson('/api/v1/website-builder/faqs', ['question' => 'On?', 'answer' => 'y'])->json('data.id');
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/content-translations', ['section' => 'faqs', 'record_id' => $faq, 'language_id' => $ta, 'values' => ['question' => 'ஆன்?']])->assertOk();
    $marketing = Tenancy::bypass(fn () => Site::query()->where('kind', 'marketing')->sole());
    $this->withToken($this->admin)->putJson('/api/v1/website-builder/basic-information', ['address' => 'x'])->assertOk();
    $this->flushHeaders();

    // By slug under the root domain, and by custom domain.
    $this->getJson("/api/v1/public/site/resolve?host={$marketing->slug}.eventinvit.app")->assertOk()
        ->assertJsonPath('data.found', true)->assertJsonPath('data.id', $marketing->id)->assertJsonPath('data.is_published', true)
        ->assertJsonPath('data.company_id', 1);
    $this->getJson('/api/v1/public/site/resolve?host=eventinvit.app')->assertOk()->assertJsonPath('data.found', false);
    $this->getJson('/api/v1/public/site/resolve?host=nobody.example.com')->assertOk()->assertJsonPath('data.found', false);

    $bundle = $this->getJson("/api/v1/public/site/bundle?host=www.{$marketing->slug}.eventinvit.app:443&lang=ta")->assertOk()
        ->assertJsonPath('data.found', true)->assertJsonPath('data.footer.copyright_text', '© RA')
        ->assertJsonPath('data.hero_sections.pricing.title', 'Plans')->assertJsonPath('data.hero_sections.home.title', 'Base')
        ->assertJsonPath('data.translations.language.code', 'ta')->assertJsonPath("data.translations.translations.faqs||{$faq}.question", 'ஆன்?')
        ->assertJsonCount(1, 'data.faqs')->assertJsonPath('data.faqs.0.question', 'On?')
        ->assertJsonCount(7, 'data.template_categories')->assertJsonCount(2, 'data.languages')->json('data');
    expect($bundle['login_settings'])->toBe([])->and($bundle['site']['slug'])->toBe($marketing->slug);

    // A vendor's own site is served the same way, from its custom domain.
    $owner = vendorOwner(['company_name' => 'Ash Events']);
    $this->withToken(accessToken($owner))->putJson('/api/v1/vendors/website', ['custom_domain' => 'https://WWW.Ash-Events.com/home'])
        ->assertOk()->assertJsonPath('data.custom_domain', 'ash-events.com');
    $this->withToken(accessToken(vendorOwner(['company_name' => 'Copycat'])))->putJson('/api/v1/vendors/website', ['custom_domain' => 'ash-events.com'])
        ->assertStatus(409)->assertJsonPath('message', 'This domain is already used by another site.');
    $this->withToken(accessToken($owner))->postJson('/api/v1/vendors/website/testimonials', ['customer_name' => 'Asha'])->assertCreated();
    $this->flushHeaders();
    $this->getJson('/api/v1/public/site/bundle', ['X-Forwarded-Host' => 'ash-events.com'])->assertOk()
        ->assertJsonPath('data.testimonials.0.customer_name', 'Asha')->assertJsonPath('data.site.status', 'draft');

    // Its domain may receive a social sign-in's return.
    expect(app(SocialSignIn::class)->isAllowedReturnTo('https://ash-events.com/login'))->toBeTrue()
        ->and(app(SocialSignIn::class)->isAllowedReturnTo('https://evil.test/login'))->toBeFalse();
});
