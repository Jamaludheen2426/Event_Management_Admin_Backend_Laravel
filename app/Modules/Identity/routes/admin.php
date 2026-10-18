<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ActivityLogController;
use App\Modules\Identity\Http\Controllers\ApprovalController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\CompanyController;
use App\Modules\Identity\Http\Controllers\DepartmentController;
use App\Modules\Identity\Http\Controllers\ModuleController;
use App\Modules\Identity\Http\Controllers\PermissionController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| Signed-in admin routes (portal.admin). Paths and permission slugs are v1's.
*/

Route::prefix('auth')->controller(AuthController::class)->group(static function (): void {
    Route::post('logout', 'logout');
    Route::get('me', 'me');
    Route::put('change-password', 'changePassword');
    Route::put('update-profile', 'updateProfile');
});

Route::prefix('users')->controller(UserController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:employees.view');
    Route::get('{id}', 'show')->middleware('perm:employees.view');
    Route::post('/', 'store')->middleware('perm:employees.create');
    Route::put('{id}', 'update')->middleware('perm:employees.edit');
    Route::delete('{id}', 'destroy')->middleware('perm:employees.delete');
    Route::patch('{id}/status', 'status')->middleware('perm:employees.edit');
});

Route::prefix('departments')->controller(DepartmentController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:departments.view');
    Route::get('{id}', 'show')->middleware('perm:departments.view');
    Route::post('/', 'store')->middleware('perm:departments.create');
    Route::put('{id}', 'update')->middleware('perm:departments.edit');
    Route::delete('{id}', 'destroy')->middleware('perm:departments.delete');
});

Route::prefix('roles')->controller(RoleController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:roles.view');
    Route::get('{id}', 'show')->middleware('perm:roles.view');
    Route::post('/', 'store')->middleware('perm:roles.manage');
    Route::put('{id}', 'update')->middleware('perm:roles.manage');
    Route::delete('{id}', 'destroy')->middleware('perm:roles.manage');
    Route::post('{id}/permissions', 'assignPermissions')->middleware('perm:roles.manage');
});

Route::prefix('permissions')->controller(PermissionController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:permissions.view');
    Route::get('{id}', 'show')->middleware('perm:permissions.view');
    Route::post('/', 'store')->middleware('perm:permissions.manage');
    Route::put('{id}', 'update')->middleware('perm:permissions.manage');
    Route::delete('{id}', 'destroy')->middleware('perm:permissions.manage');
});

Route::prefix('modules')->controller(ModuleController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:modules.view');
    Route::get('{id}', 'show')->middleware('perm:modules.view');
    Route::post('/', 'store')->middleware('perm:modules.manage');
    Route::put('{id}', 'update')->middleware('perm:modules.manage');
    Route::delete('{id}', 'destroy')->middleware('perm:modules.manage');
    Route::post('{id}/permissions', 'addPermission')->middleware('perm:modules.manage');
});

Route::prefix('companies')->middleware('developer')->controller(CompanyController::class)->group(static function (): void {
    Route::get('dashboard', 'dashboard');
    Route::get('/', 'index');
    Route::get('{id}', 'show');
    Route::post('/', 'store');
    Route::put('{id}', 'update');
    Route::delete('{id}', 'destroy');
    Route::patch('{id}/status', 'status');
});

// v1 left list, count and detail open to every signed-in admin; reviewing needs level 100.
Route::prefix('approvals')->controller(ApprovalController::class)->group(static function (): void {
    Route::get('/', 'index');
    Route::get('pending', 'pendingCount');
    Route::get('{id}', 'show');
    Route::patch('{id}/approve', 'approve')->middleware('level:100');
    Route::patch('{id}/reject', 'reject')->middleware('level:100');
    Route::delete('{id}', 'cancel');
});

Route::prefix('activity-logs')->controller(ActivityLogController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:activity_logs.view');
    Route::get('user/{userId}', 'byUser')->middleware('perm:activity_logs.view');
    Route::get('module/{module}', 'byModule')->middleware('perm:activity_logs.view');
    Route::delete('clear', 'clear')->middleware('perm:activity_logs.delete');
});
