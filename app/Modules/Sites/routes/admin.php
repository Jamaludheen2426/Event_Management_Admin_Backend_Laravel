<?php

declare(strict_types=1);

use App\Modules\Sites\Http\Controllers\ColorPaletteController;
use App\Modules\Sites\Http\Controllers\FaqCategoryController;
use App\Modules\Sites\Http\Controllers\FaqController;
use App\Modules\Sites\Http\Controllers\MarketingSiteController as C;
use App\Modules\Sites\Http\Controllers\SiteTranslationController as T;
use App\Modules\Sites\Support\SectionRoutes as S;
use Illuminate\Support\Facades\Route;

/*
| Signed-in admin routes (portal.admin): changes to the marketing site
| (website_builder.edit), its visitor messages (website_builder.view), the
| admin FAQ screen and colour palettes (v1 slugs).
*/
Route::prefix('website-builder')->group(static function (): void {
    $edit = ['perm:website_builder.edit'];

    Route::put('highlights', [C::class, 'saveHighlights'])->middleware($edit);
    Route::put('ui-blocks', [C::class, 'saveBlocks'])->middleware($edit);
    Route::put('company-ui-blocks', [C::class, 'saveBlocks'])->middleware($edit);

    foreach ([
        ['pricing/settings', 'pricing', 'Pricing settings'],
        ['basic-information', 'basic-information', 'Basic information'],
        ['hero-section', 'hero', 'Hero section'],
        ['footer', 'footer', 'Footer settings'],
        ['seo', 'seo', 'SEO settings'],
        ['login-settings', 'login', 'Login settings'],
        ['theme-settings', 'theme', 'Theme settings'],
        ['contact-settings', 'contact', 'Contact settings'],
    ] as [$path, $setting, $label]) {
        S::setting(C::class, $path, $setting, $label, false, true, $edit);
    }

    $all = ['create', 'update', 'delete'];
    foreach ([
        ['pricing/plans', 'pricingPlans', [...$all, 'replace', 'status']],
        ['pricing-plans', 'pricingPlans', ['create', 'update', 'replace']],
        ['pricing/matrix-features', 'pricingFeatures', [...$all, 'replace']],
        ['features', 'features', [...$all, 'replace', 'status']],
        ['templates/categories', 'templateCategories', [...$all, 'status']],
        ['templates', 'templates', [...$all, 'status']],
        ['how-it-works', 'steps', [...$all, 'replace', 'status']],
        ['faq-categories', 'faqCategories', [...$all, 'status']],
        ['faqs', 'faqs', [...$all, 'status']],
        ['video-tutorial-categories', 'tutorialCategories', [...$all, 'status']],
        ['video-tutorial-subcategories', 'tutorialSubcategories', [...$all, 'status']],
        ['video-tutorial-difficulty-levels', 'tutorialDifficulties', [...$all, 'status']],
        ['video-tutorial-types', 'tutorialTypes', [...$all, 'status']],
        ['video-tutorials', 'tutorials', [...$all, 'status']],
        ['social-links', 'socialLinks', ['replace']],
        ['pages', 'pages', $all],
        ['menu-items', 'menuItems', [...$all, 'replace']],
        ['sliders', 'sliders', $all],
        ['slider-items', 'slides', $all],
        ['gallery-categories', 'galleryCategories', $all],
        ['gallery-items', 'galleryItems', $all],
        ['contact-categories', 'contactCategories', $all],
        ['contact-messages', 'contactMessages', $all],
        ['testimonials', 'testimonials', [...$all, 'replace']],
        ['clients', 'clients', [...$all, 'replace']],
        ['sponsors', 'sponsors', [...$all, 'replace']],
    ] as [$path, $key, $actions]) {
        S::writes(C::class, $path, $key, $actions, $edit);
    }

    // Visitors' contact messages carry their e-mail and phone: not public.
    Route::get('contact-messages', [C::class, 'index'])->defaults('section', 'contactMessages')->middleware('perm:website_builder.view');

    // Languages and content translations.
    Route::controller(T::class)->group(static function () use ($edit): void {
        $view = ['perm:website_builder.view'];
        Route::post('translations/languages', 'createLanguage')->middleware($edit);
        Route::put('translations/languages/{id}', 'updateLanguage')->whereNumber('id')->middleware($edit);
        Route::patch('translations/languages/{id}/default', 'setDefaultLanguage')->whereNumber('id')->middleware($edit);
        Route::post('translations/languages/{id}/translate-all', 'translateAll')->whereNumber('id')->middleware($edit);
        Route::delete('translations/languages/{id}', 'deleteLanguage')->whereNumber('id')->middleware($edit);
        Route::put('content-translations', 'saveSlot')->middleware($edit);
        Route::post('content-translations/auto-translate', 'autoTranslate')->middleware($edit);
        Route::get('content-translations/auto-translate/stream', 'autoTranslateStream')->middleware($edit);
        Route::put('translation-keys/register', 'register')->middleware($edit);
        Route::get('translation-keys/sections', 'sections')->middleware($view);
        Route::get('translation-keys/stats', 'stats')->middleware($view);
        Route::get('translation-keys', 'keys')->middleware($view);
        Route::put('translation-keys/{id}/translations', 'saveKey')->whereNumber('id')->middleware($edit);
        Route::post('translation-keys/{id}/retranslate', 'retranslateKey')->whereNumber('id')->middleware($edit);
        Route::delete('translation-keys/{id}', 'deleteKey')->whereNumber('id')->middleware($edit);
    });
});

// The admin FAQ screen: the marketing site's FAQs, in v1's /faqs shapes.
Route::prefix('faq-categories')->controller(FaqCategoryController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:faq_categories.view');
    Route::get('{id}', 'show')->whereNumber('id')->middleware('perm:faq_categories.view');
    Route::post('/', 'store')->middleware('perm:faq_categories.create');
    Route::put('{id}', 'update')->whereNumber('id')->middleware('perm:faq_categories.edit');
    Route::patch('{id}/status', 'status')->whereNumber('id')->middleware('perm:faq_categories.edit');
    Route::delete('{id}', 'destroy')->whereNumber('id')->middleware('perm:faq_categories.delete');
});
Route::prefix('faqs')->controller(FaqController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:faqs.view');
    Route::get('{id}', 'show')->whereNumber('id')->middleware('perm:faqs.view');
    Route::post('/', 'store')->middleware('perm:faqs.create');
    Route::put('{id}', 'update')->whereNumber('id')->middleware('perm:faqs.edit');
    Route::delete('{id}', 'destroy')->whereNumber('id')->middleware('perm:faqs.delete');
});

Route::prefix('color-palettes')->controller(ColorPaletteController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:color_palettes.view');
    Route::get('{id}', 'show')->whereNumber('id')->middleware('perm:color_palettes.view');
    Route::post('/', 'store')->middleware('perm:color_palettes.create');
    Route::put('{id}', 'update')->whereNumber('id')->middleware('perm:color_palettes.edit');
    Route::delete('{id}', 'destroy')->whereNumber('id')->middleware('perm:color_palettes.delete');
});
