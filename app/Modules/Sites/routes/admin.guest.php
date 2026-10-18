<?php

declare(strict_types=1);

use App\Modules\Sites\Http\Controllers\MarketingSiteController as C;
use App\Modules\Sites\Http\Controllers\SiteTranslationController as T;
use App\Modules\Sites\Support\SectionRoutes as S;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/website-builder/* reads, no sign-in (v1): the marketing site's
| content, which the admin app's own preview pages show to anyone. Visitor
| messages and the translation screens are NOT here (admin.php).
*/
Route::prefix('website-builder')->group(static function (): void {
    // What the rendered site needs to show itself in another language.
    Route::get('translations/languages', [T::class, 'languages']);
    Route::get('translations/public-languages', [T::class, 'publicLanguages']);
    Route::get('translations/bundle', [T::class, 'bundle']);
    Route::get('content-translations', [T::class, 'slot']);

    Route::get('highlights', [C::class, 'highlights']);
    Route::get('ui-blocks', [C::class, 'blocks']);
    Route::get('company-ui-blocks', [C::class, 'blocks']);

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
        S::setting(C::class, $path, $setting, $label, true, false);
    }

    foreach ([
        ['pricing/plans', 'pricingPlans', false],
        ['pricing-plans', 'pricingPlans', false],
        ['pricing/matrix-features', 'pricingFeatures', false],
        ['features', 'features', false],
        ['templates/categories', 'templateCategories', false],
        ['templates', 'templates', true],
        ['how-it-works', 'steps', false],
        ['faq-categories', 'faqCategories', false],
        ['faqs', 'faqs', true],
        ['video-tutorial-categories', 'tutorialCategories', false],
        ['video-tutorial-subcategories', 'tutorialSubcategories', false],
        ['video-tutorial-difficulty-levels', 'tutorialDifficulties', false],
        ['video-tutorial-types', 'tutorialTypes', false],
        ['video-tutorials', 'tutorials', true],
        ['social-links', 'socialLinks', false],
        ['pages', 'pages', false],
        ['menu-items', 'menuItems', false],
        ['sliders', 'sliders', false],
        ['slider-items', 'slides', false],
        ['gallery-categories', 'galleryCategories', false],
        ['gallery-items', 'galleryItems', false],
        ['contact-categories', 'contactCategories', false],
        ['testimonials', 'testimonials', false],
        ['clients', 'clients', false],
        ['sponsors', 'sponsors', false],
    ] as [$path, $key, $show]) {
        S::reads(C::class, $path, $key, $show);
    }
});
