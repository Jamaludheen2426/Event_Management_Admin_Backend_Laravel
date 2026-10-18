<?php

declare(strict_types=1);

use App\Modules\Sites\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/public — no login. Any site by the host a visitor typed (the
| public site app), a vendor's public website and its contact form.
*/
Route::controller(PublicSiteController::class)->group(static function (): void {
    Route::get('site/resolve', 'resolve');
    Route::get('site/bundle', 'bundle');
    Route::get('vendors/{slug}/website-data', 'vendorWebsite');
    Route::get('website/{slug}', 'vendorWebsite');
    Route::post('vendors/{slug}/contact', 'contact')->middleware('throttle:auth');
});
