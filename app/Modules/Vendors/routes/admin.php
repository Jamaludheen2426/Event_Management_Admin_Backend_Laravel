<?php

declare(strict_types=1);

use App\Modules\Vendors\Http\Controllers\AdminVendorController;
use Illuminate\Support\Facades\Route;

/*
| Admin panel: /api/v1/vendors (portal.admin). {id} is digits only, so the
| vendor portal's own /vendors/auth, /vendors/roles... never reach these.
*/
Route::prefix('vendors')->controller(AdminVendorController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:vendors.view');
    Route::get('{id}', 'show')->middleware('perm:vendors.view');
    Route::post('/', 'store')->middleware('perm:vendors.create');
    Route::put('{id}', 'update')->middleware('perm:vendors.edit');
    Route::patch('{id}/status', 'status')->middleware('perm:vendors.edit');
    Route::post('{id}/impersonate', 'impersonate')->middleware('perm:vendors.impersonate');
    Route::delete('{id}', 'destroy')->middleware('perm:vendors.delete');
});
