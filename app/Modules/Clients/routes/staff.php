<?php

declare(strict_types=1);

use App\Modules\Clients\Http\Controllers\VendorClientController;
use Illuminate\Support\Facades\Route;

/*
| Staff portal (portal.staff): /api/v1/vendors/staff/portal/clients, with the
| v1 client.* permissions. PATCH {id} is the status toggle here (v1).
*/
Route::prefix('portal/clients')->controller(VendorClientController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('staff.perm:client.view');
    Route::get('{id}', 'show')->middleware('staff.perm:client.view');
    Route::post('/', 'store')->middleware('staff.perm:client.create');
    Route::put('{id}', 'update')->middleware('staff.perm:client.edit');
    Route::patch('{id}', 'status')->middleware('staff.perm:client.edit');
    Route::delete('{id}', 'destroy')->middleware('staff.perm:client.delete');
});
