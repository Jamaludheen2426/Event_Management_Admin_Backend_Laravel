<?php

declare(strict_types=1);

use App\Modules\Sites\Http\Controllers\VendorSiteController as C;
use App\Modules\Sites\Support\SectionRoutes as S;
use Illuminate\Support\Facades\Route;

/*
| Vendor portal (portal.vendor): /api/v1/vendors/website/* — the builder app,
| editing the signed-in vendor's own site. Paths are v1's.
*/
Route::prefix('website')->group(static function (): void {
    Route::get('schema-status', [C::class, 'schemaStatus']);
    Route::post('initialize', [C::class, 'initialize']);
    Route::get('builder-data', [C::class, 'builderData']);
    Route::get('preview-payload', [C::class, 'previewPayload']);
    Route::get('/', [C::class, 'website']);
    Route::put('/', [C::class, 'updateWebsite']);
    Route::post('publish', [C::class, 'publish']);

    foreach ([
        ['basic-information', 'basic-information', 'Basic information'],
        ['hero-section', 'hero', 'Hero section'],
        ['footer', 'footer', 'Footer settings'],
        ['seo', 'seo', 'SEO settings'],
        ['contact/settings', 'contact', 'Contact settings'],
        ['pricing/settings', 'pricing', 'Pricing settings'],
    ] as [$path, $setting, $label]) {
        S::setting(C::class, $path, $setting, $label, true, true);
    }

    Route::get('ui-blocks', [C::class, 'blocks']);
    Route::put('ui-blocks', [C::class, 'saveBlocks']);

    Route::get('sliders/{sliderId}/items', [C::class, 'slides'])->whereNumber('sliderId');
    Route::put('sliders/{sliderId}/items', [C::class, 'replaceSlides'])->whereNumber('sliderId');
    Route::post('sliders/{sliderId}/items', [C::class, 'storeSlide'])->whereNumber('sliderId');
    S::writes(C::class, 'slider-items', 'slides', ['update', 'delete']);

    foreach ([
        ['social-links', 'socialLinks', ['replace']],
        ['pages', 'pages', ['create', 'update', 'delete']],
        ['menu-items', 'menuItems', ['replace']],
        ['sliders', 'sliders', ['create', 'update', 'delete']],
        ['gallery/categories', 'galleryCategories', ['create', 'update', 'delete']],
        ['gallery/items', 'galleryItems', ['create', 'update', 'delete']],
        ['contact/social-links', 'contactSocialLinks', ['replace']],
        ['contact/categories', 'contactCategories', ['create', 'update', 'delete']],
        ['contact/messages', 'contactMessages', ['create', 'update', 'delete']],
        ['testimonials', 'testimonials', ['create', 'replace', 'update', 'delete']],
        ['portfolio/clients', 'clients', ['create', 'replace', 'update', 'delete']],
        ['portfolio/sponsors', 'sponsors', ['create', 'replace', 'update', 'delete']],
        ['pricing/plans', 'pricingPlans', ['replace']],
        ['pricing/matrix-features', 'pricingFeatures', ['replace']],
        ['features', 'features', ['create', 'update', 'delete', 'replace']],
    ] as [$path, $key, $actions]) {
        S::reads(C::class, $path, $key);
        S::writes(C::class, $path, $key, $actions);
    }
});
