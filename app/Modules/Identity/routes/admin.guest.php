<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/auth — no login required. Self-registration stays disabled, as in v1.
*/
Route::prefix('auth')->name('admin.auth.')->controller(AuthController::class)->group(static function (): void {
    Route::post('login', 'login')->middleware('throttle:auth')->name('login');
    Route::post('refresh', 'refresh')->middleware('throttle:refresh')->name('refresh');
    Route::post('refresh-token', 'refresh')->middleware('throttle:refresh');
    Route::post('forgot-password', 'forgotPassword')->middleware('throttle:code')->name('forgot-password');
    Route::post('verify-reset-otp', 'verifyResetOtp')->middleware('throttle:code')->name('verify-reset-otp');
    Route::post('reset-password', 'resetPassword')->middleware('throttle:code')->name('reset-password');
});
