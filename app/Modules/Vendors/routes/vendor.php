<?php

declare(strict_types=1);

use App\Modules\Vendors\Http\Controllers\StaffController;
use App\Modules\Vendors\Http\Controllers\VendorAuthController;
use App\Modules\Vendors\Http\Controllers\VendorDepartmentController;
use App\Modules\Vendors\Http\Controllers\VendorRoleController;
use App\Modules\Vendors\Http\Controllers\VendorSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
| Vendor portal (portal.vendor: the owner). The owner passes every vendor
| permission, so these routes need none.
*/

Route::prefix('auth')->controller(VendorAuthController::class)->group(static function (): void {
    Route::get('me', 'me');
    Route::put('profile', 'updateProfile');
    Route::post('change-password', 'changePassword');
    Route::post('handoff-token', 'handoffToken');
    Route::get('activity', 'activity');
    Route::get('about', 'about');
    Route::put('about', 'updateAbout');
    Route::post('upload', 'upload');
});

Route::prefix('roles')->controller(VendorRoleController::class)->group(static function (): void {
    Route::get('/', 'index');
    Route::get('{id}', 'show');
    Route::post('/', 'store');
    Route::put('{id}', 'update');
    Route::delete('{id}', 'destroy');
    Route::put('{id}/permissions', 'assignPermissions');
});
Route::get('modules', [VendorRoleController::class, 'modules']);
Route::get('permissions', [VendorRoleController::class, 'permissions']);

Route::prefix('departments')->controller(VendorDepartmentController::class)->group(static function (): void {
    Route::get('/', 'index');
    Route::get('{id}', 'show');
    Route::post('/', 'store');
    Route::put('{id}', 'update');
    Route::delete('{id}', 'destroy');
});

Route::prefix('staff')->controller(StaffController::class)->group(static function (): void {
    Route::get('/', 'index');
    Route::get('{id}', 'show');
    Route::post('/', 'store');
    Route::put('{id}', 'update');
    Route::put('{id}/role', 'assignRole');
    Route::patch('{id}/status', 'status');
    Route::delete('{id}', 'destroy');
});

Route::get('subscription', [VendorSubscriptionController::class, 'show']);
