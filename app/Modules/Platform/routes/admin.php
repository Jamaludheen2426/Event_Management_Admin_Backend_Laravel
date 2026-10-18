<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\CurrencyController;
use App\Modules\Platform\Http\Controllers\LanguageController;
use App\Modules\Platform\Http\Controllers\LocationController;
use App\Modules\Platform\Http\Controllers\MediaController;
use App\Modules\Platform\Http\Controllers\PluginController;
use App\Modules\Platform\Http\Controllers\SettingController;
use App\Modules\Platform\Http\Controllers\TranslationController;
use App\Modules\Platform\Http\Controllers\TranslationKeyController;
use Illuminate\Support\Facades\Route;

/*
| Signed-in admin routes (portal.admin). Paths and permission slugs are v1's.
*/

Route::prefix('settings')->controller(SettingController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:settings.view');
    Route::get('group/{group}', 'group')->middleware('perm:settings.view');
    Route::post('bulk', 'bulk')->middleware('perm:settings.edit');
    Route::get('{key}', 'show')->middleware('perm:settings.view');
    Route::put('{key}', 'update')->middleware('perm:settings.edit');
});

Route::prefix('plugins')->controller(PluginController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:plugins.view');
    Route::get('{slug}', 'show')->middleware('perm:plugins.view');
    Route::put('{slug}/toggle', 'toggle')->middleware('perm:plugins.manage');
});

Route::prefix('languages')->controller(LanguageController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:languages.view');
    Route::get('{id}', 'show')->middleware('perm:languages.view');
    Route::post('/', 'store')->middleware('perm:languages.create');
    Route::put('{id}', 'update')->middleware('perm:languages.edit');
    Route::delete('{id}', 'destroy')->middleware('perm:languages.delete');
    Route::patch('{id}/default', 'setDefault')->middleware('perm:languages.edit');
});

Route::prefix('currencies')->controller(CurrencyController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:currencies.view');
    Route::get('{id}', 'show')->middleware('perm:currencies.view');
    Route::post('/', 'store')->middleware('perm:currencies.create');
    Route::put('{id}', 'update')->middleware('perm:currencies.edit');
    Route::delete('{id}', 'destroy')->middleware('perm:currencies.delete');
    Route::patch('{id}/default', 'setDefault')->middleware('perm:currencies.edit');
});

Route::prefix('locations')->controller(LocationController::class)->group(static function (): void {
    foreach (['countries' => 'country', 'states' => 'state', 'districts' => 'district', 'cities' => 'city'] as $path => $level) {
        Route::post($path, 'store')->defaults('level', $level)->middleware('perm:locations.create');
        Route::post("{$path}/bulk", 'bulk')->defaults('level', $level)->middleware('perm:locations.create');
        Route::put("{$path}/{id}", 'update')->defaults('level', $level)->middleware('perm:locations.edit');
        Route::delete("{$path}/{id}", 'destroy')->defaults('level', $level)->middleware('perm:locations.delete');
    }
});

Route::prefix('media')->controller(MediaController::class)->group(static function (): void {
    Route::get('files', 'files')->middleware('perm:media.view');
    Route::get('proxy', 'proxy');
    Route::post('folder', 'createFolder')->middleware('perm:media.upload');
    Route::post('upload', 'upload')->middleware('perm:media.upload');
    Route::post('upload-multiple', 'uploadMultiple')->middleware('perm:media.upload');
    Route::post('rename', 'rename')->middleware('perm:media.upload');
    Route::post('copy', 'copy')->middleware('perm:media.upload');
    Route::post('move', 'move')->middleware('perm:media.upload');
    Route::delete('/', 'destroy')->middleware('perm:media.delete');
});

Route::prefix('translation-keys')->controller(TranslationKeyController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:translations.view');
    Route::post('/', 'store')->middleware('perm:translations.create');
    Route::post('bulk-import', 'bulkImport')->middleware('perm:translations.create');
    Route::get('{id}', 'show')->middleware('perm:translations.view');
    Route::put('{id}', 'update')->middleware('perm:translations.edit');
    Route::delete('{id}', 'destroy')->middleware('perm:translations.delete');
    Route::get('{id}/translations', 'values')->middleware('perm:translations.view');
    Route::put('{id}/translations', 'updateValues')->middleware('perm:translations.edit');
    Route::post('{id}/retranslate', 'retranslate')->middleware('perm:translations.manage');
    Route::post('{id}/retranslate-all', 'retranslateAll')->middleware('perm:translations.manage');
});

Route::prefix('translations')->controller(TranslationController::class)->group(static function (): void {
    Route::get('missing', 'missing')->middleware('perm:translations.view');
    Route::post('missing/create-all', 'createAllMissing')->middleware('perm:translations.manage');
    Route::post('missing/{id}/create', 'createFromMissing')->middleware('perm:translations.manage');
    Route::post('missing/{id}/ignore', 'ignoreMissing')->middleware('perm:translations.manage');
    Route::delete('missing/{id}', 'deleteMissing')->middleware('perm:translations.manage');
    Route::post('translate-all', 'translateAll')->middleware('perm:translations.manage');
});
