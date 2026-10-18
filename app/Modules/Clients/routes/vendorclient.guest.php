<?php

declare(strict_types=1);

use App\Modules\Clients\Http\Controllers\VendorClientPortalController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/vendors/client/auth — v1 vendor-client portal, no login required.
*/
Route::prefix('auth')->controller(VendorClientPortalController::class)->group(static function (): void {
    Route::post('handoff', 'handoff')->middleware('throttle:auth');
    Route::post('forgot-password', 'forgotPassword')->middleware('throttle:code');
    Route::post('reset-password', 'resetPassword')->middleware('throttle:code');
});
