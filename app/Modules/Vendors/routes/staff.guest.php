<?php

declare(strict_types=1);

use App\Modules\Vendors\Http\Controllers\StaffAuthController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/vendors/staff/auth — no login required.
*/
Route::prefix('auth')->controller(StaffAuthController::class)->group(static function (): void {
    Route::post('login', 'login')->middleware('throttle:auth');
    Route::post('forgot-password', 'forgotPassword')->middleware('throttle:code');
    Route::post('verify-reset-otp', 'verifyResetOtp')->middleware('throttle:code');
    Route::post('reset-password', 'resetPassword')->middleware('throttle:code');
});
