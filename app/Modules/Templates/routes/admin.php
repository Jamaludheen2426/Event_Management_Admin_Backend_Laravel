<?php

declare(strict_types=1);

use App\Modules\Templates\Http\Controllers\DecorationController;
use App\Modules\Templates\Http\Controllers\EventTemplateController;
use App\Modules\Templates\Http\Controllers\FrameStyleController;
use App\Modules\Templates\Http\Controllers\TemplateCategoryController;
use App\Modules\Templates\Http\Controllers\TemplateFontController;
use Illuminate\Support\Facades\Route;

/*
| Signed-in admin routes (portal.admin). Paths and permission slugs are v1's.
*/

$crud = static function (string $path, string $controller, string $permission): void {
    Route::prefix($path)->controller($controller)->group(static function () use ($permission): void {
        Route::get('/', 'index')->middleware("perm:{$permission}.view");
        Route::get('{id}', 'show')->middleware("perm:{$permission}.view");
        Route::post('/', 'store')->middleware("perm:{$permission}.create");
        Route::put('{id}', 'update')->middleware("perm:{$permission}.edit");
        Route::patch('{id}/status', 'status')->middleware("perm:{$permission}.edit");
        Route::delete('{id}', 'destroy')->middleware("perm:{$permission}.delete");
    });
};

Route::patch('template-categories/reorder', [TemplateCategoryController::class, 'reorder'])->middleware('perm:template_categories.edit');
$crud('template-categories', TemplateCategoryController::class, 'template_categories');

Route::prefix('frame-styles')->controller(FrameStyleController::class)->group(static function (): void {
    Route::get('stats', 'stats')->middleware('perm:frame_styles.view');
    Route::get('svg-source', 'svgSource')->middleware('perm:frame_styles.view');
    Route::post('recolor', 'recolor')->middleware('perm:frame_styles.edit');
});
$crud('frame-styles', FrameStyleController::class, 'frame_styles');

Route::prefix('decorations')->controller(DecorationController::class)->group(static function (): void {
    Route::get('stats', 'stats')->middleware('perm:decorations.view');
    Route::patch('reorder', 'reorder')->middleware('perm:decorations.edit');
    Route::get('svg-source', 'svgSource')->middleware('perm:decorations.view');
    Route::post('recolor', 'recolor')->middleware('perm:decorations.edit');
});
$crud('decorations', DecorationController::class, 'decorations');

Route::prefix('event-templates')->controller(EventTemplateController::class)->group(static function (): void {
    Route::get('stats', 'stats')->middleware('perm:event_templates.view');
    Route::patch('reorder', 'reorder')->middleware('perm:event_templates.edit');
    Route::patch('{id}/featured', 'featured')->middleware('perm:event_templates.edit');
    Route::post('{id}/duplicate', 'duplicate')->middleware('perm:event_templates.create');
});
$crud('event-templates', EventTemplateController::class, 'event_templates');

Route::prefix('template-fonts')->controller(TemplateFontController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:event_templates.view');
    Route::post('/', 'store')->middleware('perm:event_templates.create');
    Route::put('{id}', 'update')->middleware('perm:event_templates.edit');
    Route::delete('{id}', 'destroy')->middleware('perm:event_templates.delete');
});
