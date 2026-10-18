<?php

declare(strict_types=1);

use App\Modules\Clients\Http\Controllers\SubscriberController;
use App\Modules\Clients\Http\Controllers\VendorClientController;
use Illuminate\Support\Facades\Route;

/*
| Vendor portal (portal.vendor, owner only): /api/v1/vendors/clients and
| /api/v1/vendors/subscribers.
*/
Route::prefix('clients')->controller(VendorClientController::class)->group(static function (): void {
    Route::get('/', 'index');
    Route::get('{id}', 'show');
    Route::post('/', 'store');
    Route::put('{id}', 'update');
    Route::patch('{id}/status', 'status');
    Route::delete('{id}', 'destroy');
});

Route::prefix('subscribers')->controller(SubscriberController::class)->group(static function (): void {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::put('{id}', 'update');
    Route::delete('{id}', 'destroy');
});
