<?php

declare(strict_types=1);

use App\Modules\Clients\Http\Controllers\VendorClientPortalController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1/vendors/client — v1 vendor-client portal, signed in (portal.vendorclient).
*/
Route::controller(VendorClientPortalController::class)->group(static function (): void {
    Route::post('auth/logout', 'logout');
    Route::get('auth/me', 'me');
    Route::put('auth/profile', 'updateProfile');
    Route::put('auth/change-password', 'changePassword');
    Route::get('subscription/plans', 'plans');
});
