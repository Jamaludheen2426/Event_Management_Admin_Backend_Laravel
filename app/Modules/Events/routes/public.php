<?php

declare(strict_types=1);

use App\Modules\Events\Http\Controllers\GuestJoinController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/public/events — no login: a guest scans an event's QR, sees the
| invitation and verifies their phone before joining.
*/
Route::prefix('events')->controller(GuestJoinController::class)->group(static function (): void {
    Route::post('qr/resolve', 'resolve')->middleware('throttle:auth');
    Route::post('join/otp/request', 'requestCode')->middleware('throttle:auth');
    Route::post('join/otp/verify', 'verifyCode')->middleware('throttle:code');
});
