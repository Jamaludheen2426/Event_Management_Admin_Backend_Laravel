<?php

declare(strict_types=1);

use App\Modules\Clients\Http\Controllers\AdminClientController;
use Illuminate\Support\Facades\Route;

/*
| Admin panel: /api/v1/website-clients (portal.admin). Create, edit and delete
| may need approval; status is a reversible toggle and does not (v1).
*/
Route::prefix('website-clients')->controller(AdminClientController::class)->group(static function (): void {
    Route::get('/', 'index')->middleware('perm:website_clients.view');
    Route::get('stats', 'stats')->middleware('perm:website_clients.view');
    Route::get('{id}', 'show')->middleware('perm:website_clients.view');
    Route::post('/', 'store')->middleware('perm:website_clients.create');
    Route::put('{id}', 'update')->middleware('perm:website_clients.edit');
    Route::patch('{id}/status', 'status')->middleware('perm:website_clients.edit');
    Route::delete('{id}', 'destroy')->middleware('perm:website_clients.delete');
});
