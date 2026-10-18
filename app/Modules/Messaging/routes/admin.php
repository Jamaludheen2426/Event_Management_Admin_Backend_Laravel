<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\EmailConfigController;
use App\Modules\Messaging\Http\Controllers\EmailTemplateController;
use App\Modules\Messaging\Http\Controllers\PushConfigController;
use Illuminate\Support\Facades\Route;

/*
| Signed-in admin routes (portal.admin). Paths and permission slugs are v1's.
*/
Route::prefix('email-configs')->group(static function (): void {
    Route::get('/', [EmailConfigController::class, 'index'])->middleware('perm:email_configs.view');
    Route::get('{id}', [EmailConfigController::class, 'show'])->middleware('perm:email_configs.view');
    Route::post('/', [EmailConfigController::class, 'store'])->middleware('perm:email_configs.create');
    Route::put('{id}', [EmailConfigController::class, 'update'])->middleware('perm:email_configs.edit');
    Route::delete('{id}', [EmailConfigController::class, 'destroy'])->middleware('perm:email_configs.delete');
    Route::post('{id}/test', [EmailConfigController::class, 'test'])->middleware('perm:email_configs.manage');
    Route::get('{id}/debug', [EmailConfigController::class, 'debug'])->middleware('perm:email_configs.manage');
    Route::patch('{id}/toggle', [EmailConfigController::class, 'toggle'])->middleware('perm:email_configs.edit');
});

Route::prefix('email-templates')->group(static function (): void {
    Route::get('parts', [EmailTemplateController::class, 'parts'])->middleware('perm:email_templates.view');
    Route::get('variables', [EmailTemplateController::class, 'variables'])->middleware('perm:email_templates.view');
    Route::get('/', [EmailTemplateController::class, 'index'])->middleware('perm:email_templates.view');
    Route::get('{id}', [EmailTemplateController::class, 'show'])->middleware('perm:email_templates.view');
    Route::post('/', [EmailTemplateController::class, 'store'])->middleware('perm:email_templates.create');
    Route::put('{id}', [EmailTemplateController::class, 'update'])->middleware('perm:email_templates.update');
    Route::patch('{id}/toggle-active', [EmailTemplateController::class, 'toggle'])->middleware('perm:email_templates.update');
    Route::delete('{id}', [EmailTemplateController::class, 'destroy'])->middleware('perm:email_templates.delete');
    Route::post('{id}/preview', [EmailTemplateController::class, 'preview'])->middleware('perm:email_templates.view');
    Route::post('{id}/send', [EmailTemplateController::class, 'send'])->middleware('perm:email_templates.manage');
});

Route::prefix('push-notification-configs')->group(static function (): void {
    Route::get('active', [PushConfigController::class, 'active'])->middleware('perm:settings.view');
    Route::get('/', [PushConfigController::class, 'index'])->middleware('perm:settings.view');
    Route::get('{id}', [PushConfigController::class, 'show'])->middleware('perm:settings.view');
    Route::post('/', [PushConfigController::class, 'store'])->middleware('perm:settings.edit');
    Route::put('{id}', [PushConfigController::class, 'update'])->middleware('perm:settings.edit');
    Route::delete('{id}', [PushConfigController::class, 'destroy'])->middleware('perm:settings.edit');
    Route::patch('{id}/toggle', [PushConfigController::class, 'activate'])->middleware('perm:settings.edit');
    Route::patch('{id}/active', [PushConfigController::class, 'activate'])->middleware('perm:settings.edit');
    Route::post('{id}/test', [PushConfigController::class, 'test'])->middleware('perm:settings.edit');
});
