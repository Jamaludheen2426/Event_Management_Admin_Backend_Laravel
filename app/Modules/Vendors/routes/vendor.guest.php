<?php

declare(strict_types=1);

use App\Modules\Vendors\Http\Controllers\VendorAuthController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/vendors/auth — no login required.
*/
Route::prefix('auth')->controller(VendorAuthController::class)->group(static function (): void {
    Route::post('login', 'login')->middleware('throttle:auth');
    Route::post('logout', 'logout');
    Route::post('forgot-password', 'forgotPassword')->middleware('throttle:code');
    Route::post('reset-password', 'resetPassword')->middleware('throttle:code');
    Route::post('handoff', 'handoff')->middleware('throttle:auth');
});
