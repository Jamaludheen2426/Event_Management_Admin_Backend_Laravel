<?php

declare(strict_types=1);

use App\Modules\Vendors\Http\Controllers\StaffAuthController;
use App\Modules\Vendors\Http\Controllers\StaffController;
use App\Modules\Vendors\Http\Controllers\VendorRoleController;
use Illuminate\Support\Facades\Route;

/*
| Staff portal (portal.staff): /api/v1/vendors/staff/auth/* and
| /api/v1/vendors/staff/portal/*. Permission slugs are v1's.
| Staff cannot change anyone's active state or login access here (v1: no PATCH).
*/

Route::prefix('auth')->controller(StaffAuthController::class)->group(static function (): void {
    Route::post('logout', 'logout');
    Route::get('me', 'me');
    Route::put('change-password', 'changePassword');
});

Route::prefix('portal')->group(static function (): void {
    Route::prefix('staff')->controller(StaffController::class)->group(static function (): void {
        Route::get('/', 'index')->middleware('staff.perm:staff.view');
        Route::get('{id}', 'show')->middleware('staff.perm:staff.view');
        Route::post('/', 'store')->middleware('staff.perm:staff.create');
        Route::put('{id}', 'update')->middleware('staff.perm:staff.edit');
        Route::delete('{id}', 'destroy')->middleware('staff.perm:staff.delete');
    });

    Route::prefix('roles')->controller(VendorRoleController::class)->group(static function (): void {
        Route::get('/', 'index')->middleware('staff.perm:roles.view');
        Route::get('{id}', 'show')->middleware('staff.perm:roles.view');
        Route::post('/', 'store')->middleware('staff.perm:roles.create');
        Route::put('{id}', 'update')->middleware('staff.perm:roles.edit');
        Route::delete('{id}', 'destroy')->middleware('staff.perm:roles.delete');
        Route::put('{id}/permissions', 'assignPermissions')->middleware('staff.perm:roles.edit');
    });

    Route::get('modules', [VendorRoleController::class, 'modules']);
    Route::get('permissions', [VendorRoleController::class, 'permissions']);
});
